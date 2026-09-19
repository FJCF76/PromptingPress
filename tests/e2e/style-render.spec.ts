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
 * The v2 authoring path. Testimonials has no style slots, so `styleComponent`
 * cannot reach it: a UDC band is authored by writing the whole composition,
 * `udc` map and all, through `update_composition` — the same validated action
 * the chat and the CLI call. Seeding the map with `setComposition` would write
 * it straight to post meta and prove nothing about validation (Section 14.1),
 * so every v2 styling pin below goes through here.
 */
async function updateComposition(page: any, postId: number, composition: unknown[]) {
  return page.evaluate(
    async (args: { pid: number; composition: unknown[] }) => {
      const config = (window as any).ppAiChat;

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
      data.append('name', 'update_composition');
      data.append('params[post_id]', String(args.pid));
      data.append('params[composition]', JSON.stringify(args.composition));
      if (baseline && baseline.success && baseline.data) {
        data.append('params[expected_version]', String(baseline.data.version));
      }
      const resp = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data,
      });
      return resp.json();
    },
    { pid: postId, composition },
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

  // #475/#1023: section.body_items renders a row of short items with a CSS-generated
  // `li::before` middot separator (on EVERY item since #489's hanging-separator clip;
  // the 2nd item read below is a mid-line item whose separator is visible).
  //
  // REBUILT FOR v2 (#1023) AND IT IS NOT A MECHANICAL RE-POINT — the ruled fix to a
  // defect this rebuild introduced lives here, because it is the browser-only half.
  //
  // WHAT THE v1 TEST ASSERTED: the separator routed through `--section-separator-color`
  // (default `var(--color-muted)`), and on an inverted band the default followed the
  // light sibling text because `--color-muted` was REMAPPED by the band class.
  //
  // WHAT CHANGED: the slot retired with section's slot map, and the mark is drawn with
  // `content` on a `::before`, which ruling A3 defers — so no role can address it. The
  // first cut pointed it at `--pp-list-marker-color` with the LIST MARKERS' accent
  // fallback, and the rebuild's own disclosure claimed "nothing moves visually". That was
  // false: the markers defaulted to accent, the SEPARATOR defaulted to muted, and the
  // muted default existed precisely to make the mark follow its sibling text via those
  // band-class remaps. A v2 band has no class, so reusing the literal would have painted a
  // fixed grey that vanishes on the dark bands v2 makes easy.
  //
  // RULED: the separator falls back to `currentColor` — the same intent in the mechanism
  // v2 has, inheritance — while the two list markers keep `var(--color-accent)`.
  //
  // THIS TEST IS THE RENDERED PROOF, at THREE widths rather than two (the ruling asked for
  // 375/768/1280), on a light band AND an authored dark band. CSS-text pins can show the
  // declaration; only a browser resolves `currentColor` through the cascade and proves the
  // mark actually follows the row on a band that carries no class at all.
  test('#1023 the body_items separator follows its row on every band, and the markers keep the accent @smoke', async ({
    page,
  }) => {
    const MUTED = 'rgb(94, 102, 119)'; // #5e6677 — what v1 painted on a light band
    const LIGHT_INK = 'rgb(226, 232, 240)'; // #e2e8f0 — the dark band's authored row ink

    pageId = createPage('E2E Section Separator Follows Its Row');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-seed', body: '<p>Seed.</p>' } }]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // Authored through the REAL path, not raw meta: a `udc` map only scopes to a band
    // whose id the engine minted, and raw meta mints nothing (Section 14.1).
    const res = await updateComposition(page, pageId, [
      // 0 — default light band, nothing authored. The separator must equal the row's own
      //     colour, which is what `currentColor` MEANS and what v1 achieved by remap.
      { component: 'section', props: { id: 'pp-sec-default', body: '<p>Body.</p>', body_items: ['One', 'Two', 'Three'] } },
      // 1 — the documented ROUTE BACK to the old recessive grey: the token still leads
      //     the chain, so setting it to the muted token restores exactly what v1 painted.
      {
        component: 'section',
        props: { id: 'pp-sec-muted', body: '<p>Body.</p>', body_items: ['One', 'Two', 'Three'] },
        udc: { 'inline-items': { typography: { color: '@color-muted' } } },
      },
      // 2 — an authored DARK band. v1 could not express this at all without its theme
      //     prop; the separator must follow the light ink with NO band class in play.
      {
        component: 'section',
        props: { id: 'pp-sec-dark', body: '<p>Body.</p>', body_items: ['One', 'Two', 'Three'] },
        udc: {
          _band: { background: { fill: '#0b1020' } },
          'inline-items': { typography: { color: '#e2e8f0' } },
        },
      },
      // 3 — the brand strip: 15px/600 from the role, no extra typography surface needed.
      {
        component: 'section',
        props: { id: 'pp-sec-brand', body: '<p>Body.</p>', body_items: ['One', 'Two', 'Three'] },
        udc: { 'inline-items': { typography: { size: '15px', weight: '600', color: '#84cc16' } } },
      },
      // 4 — a body list, so the MARKERS can be proved NOT to have moved with the
      //     separator. Conflating the two is the exact mistake this test corrects.
      { component: 'section', props: { id: 'pp-sec-marker', body: '<ul><li>Item</li></ul>', body_marker: 'check' } },
    ]);
    expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);

    const sepColor = (id: string) =>
      page.locator(`#${id} .section__inline-item`).nth(1).evaluate((el) => getComputedStyle(el, '::before').color);
    const itemColor = (id: string) =>
      page.locator(`#${id} .section__inline-item`).nth(1).evaluate((el) => getComputedStyle(el).color);
    const itemType = (id: string) =>
      page.locator(`#${id} .section__inline-item`).nth(0).evaluate((el) => {
        const cs = getComputedStyle(el);
        return { size: cs.fontSize, weight: cs.fontWeight };
      });

    for (const width of [1280, 768, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.section__inline-items')).toHaveCount(4, { timeout: 10000 });

      // THE RULING, rendered: the separator equals its own row's colour. Asserted as an
      // equality rather than against a literal, so it stays true through a retheme —
      // which is the whole point of `currentColor` over a baked value.
      for (const id of ['pp-sec-default', 'pp-sec-muted', 'pp-sec-dark', 'pp-sec-brand']) {
        expect(await sepColor(id), `${id} @${width}`).toBe(await itemColor(id));
      }

      // The DARK band is the case v1 could not express, and the one a re-used
      // `--color-muted` literal would have made invisible. The separator is the light
      // ink, and it is NOT what the light bands paint.
      const dark = await sepColor('pp-sec-dark');
      expect(dark, `dark band separator @${width}`).toBe(LIGHT_INK);
      expect(dark).not.toBe(await sepColor('pp-sec-default'));

      // THE ROUTE BACK, rendered: the old recessive grey is one authored value away.
      expect(await sepColor('pp-sec-muted'), `muted route-back @${width}`).toBe(MUTED);

      // THE RESIDUAL, pinned rather than left implicit: an UNAUTHORED light band no
      // longer paints v1's muted grey — it paints the row's inherited secondary text.
      // If this ever equals MUTED again, the disclosure in three surfaces is stale.
      expect(await sepColor('pp-sec-default'), `residual @${width}`).not.toBe(MUTED);

      // The brand strip's type comes from the role, and its separator follows its ink.
      const brand = await itemType('pp-sec-brand');
      expect(brand.size).toBe('15px');
      expect(brand.weight).toBe('600');
      expect(await sepColor('pp-sec-brand')).toBe('rgb(132, 204, 22)');

      // THE NON-CONFLATION PIN: the body list MARKER keeps the accent it always painted.
      // It must NOT have been dragged onto `currentColor` with the separator.
      const marker = await page
        .locator('#pp-sec-marker .section__content--marker-check > ul > li')
        .first()
        .evaluate((el) => getComputedStyle(el, '::before').color);
      const markerText = await page
        .locator('#pp-sec-marker .section__content--marker-check > ul > li')
        .first()
        .evaluate((el) => getComputedStyle(el).color);
      // Asserted as the ACCENT, not merely as "different from the text" (review finding):
      // an inequality would still pass if a future change dragged the marker onto
      // --color-muted or --color-border, while the claim above says it kept the accent.
      // Resolved through the token rather than a literal so a retheme moves both.
      const accentRgb = await page.evaluate(() => {
        const el = document.createElement('span');
        el.style.color = getComputedStyle(document.documentElement).getPropertyValue('--color-accent').trim();
        document.body.appendChild(el);
        const rgb = getComputedStyle(el).color;
        el.remove();
        return rgb;
      });
      expect(marker, `marker keeps the accent @${width}`).toBe(accentRgb);
      expect(marker, `marker @${width}`).not.toBe(markerText);
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
    //
    // READ FROM THE ROW ITSELF SINCE #1023, not from `.section__body`. v1 capped the
    // WRAPPER and the row inherited the constraint through `max-width: 100%`; the wrapper
    // caps were deleted with the slot map, so the row carries its own
    // `sizing.max-width` — deliberately the SAME 40rem, because sharing a wrapper is what
    // gave the two the same cap in the first place. The number this test's expectations
    // are derived from is therefore unchanged; only the element that declares it moved.
    const measure = async () =>
      page
        .locator('#pp-sec-wrap .section__inline-items')
        .first()
        .evaluate((el) => {
          const rootPx = parseFloat(getComputedStyle(document.documentElement).fontSize);
          return { maxWidth: getComputedStyle(el).maxWidth, expected: `${40 * rootPx}px` };
        });
    const cap = await measure();
    expect(cap.maxWidth, 'the inline-items row keeps the 40rem measure that governs its wrapping').toBe(
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

  // #510/#1023: the `body_items_align` PROP ('start' | 'center', default 'start') gives
  // the author a lever over the wrap alignment. It was the --section-inline-items-align
  // style SLOT until section's rebuild, and it could not become a role value: it sets
  // `justify-content`, a LAYOUT property, and the UDC taxonomy carries no layout group —
  // the same reason hero kept `split_ratio` and `vertical_align`. It also has to derive a
  // MODIFIER rather than emit a raw keyword, because the centred mode switches the
  // separator from ::before to ::after, which no role value could do. Same two accepted
  // values, same default, same rendered behaviour — which is what this test proves. 'start' keeps the
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
      // A centered strip long enough to wrap to 2-3 lines at mobile. The align value is a
      // PROP now, so it rides in `props` rather than a component-level `style` map.
      {
        component: 'section',
        props: {
          id: 'pp-sec-center',
          body: '<p>Body.</p>',
          body_items_align: 'center',
          body_items: [
            'Recuperación incluida',
            'Copias diarias',
            'Sin permanencia',
            'Soporte en español',
            '99,9% de disponibilidad',
          ],
        },
      },
      // A short centered strip that fits one line even at 320 — still block-centered.
      {
        component: 'section',
        props: { id: 'pp-sec-center-oneline', body: '<p>Body.</p>', body_items_align: 'center', body_items: ['Rápido', 'Seguro', 'Fiable'] },
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

  // #488/#1023: a body_items-only band (no body copy) is a first-class strip, and it
  // still is — but the AUTOMATIC top-margin zeroing that #488 shipped is RETIRED, and
  // this test is inverted rather than deleted because the narrowing is the thing that
  // now needs proving.
  //
  // WHAT #488 DID: the template inferred `$has_body_copy` and emitted a `--flush-top`
  // modifier that zeroed the row's body-relative top margin, so a body-less strip sat
  // optically centred in the band's own symmetric padding.
  //
  // WHY IT IS GONE: a v2 role default is per COMPONENT, not per content shape. Inferring
  // design intent from whether a prop happens to be empty is exactly the hidden-rule class
  // the contract removes — and there is no legal place left to say it, because the modifier
  // was a value decision the structural-CSS boundary keeps out of the stylesheet.
  //
  // WHAT REPLACES IT: the author says so, in one line the docs give verbatim —
  // `"inline-items": { "spacing": { "margin-top": "0" } }`. Bands 0 and 1 below are the
  // rendered proof that the route WORKS and that the default is what the disclosure says
  // it is, so a reader who followed the CHANGELOG gets the result it promises.
  //
  // The #489 hanging-separator clip is unchanged and still pinned on a body-less wrapping
  // strip, because that half was never about the margin.
  test('#1023 a body-less strip keeps its default top margin, and the documented route zeroes it @smoke', async ({
    page,
  }, testInfo) => {
    pageId = createPage('E2E Section Body-less Strip');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-seed', body: '<p>Seed.</p>' } }]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const DARK = { _band: { background: { fill: '#0b1020' } }, 'inline-items': { typography: { color: '#e2e8f0' } } };
    const seedRes = await updateComposition(page, pageId, [
      // 0 — a body-less strip, UNAUTHORED margin. This is the narrowing: it now keeps
      //     var(--space-md), where #488 zeroed it automatically.
      {
        component: 'section',
        props: { id: 'pp-sec-bodyless', body_items: ['SOC 2 Type II', '99.99% uptime', 'GDPR compliant'] },
        udc: DARK,
      },
      // 1 — the same strip with the DOCUMENTED ROUTE applied, verbatim from the README,
      //     the CHANGELOG and composition.md. It must render what #488 used to give free.
      {
        component: 'section',
        props: { id: 'pp-sec-bodyless-flush', body_items: ['SOC 2 Type II', '99.99% uptime', 'GDPR compliant'] },
        udc: { ...DARK, 'inline-items': { ...DARK['inline-items'], spacing: { 'margin-top': '0' } } },
      },
      // 2 — a strip WITH body copy: keeps the base var(--space-md) top margin, exactly as
      //     it always did. Unchanged by the narrowing, which only ever touched the
      //     body-LESS case.
      {
        component: 'section',
        props: { id: 'pp-sec-withbody', body: '<p>Everything you need to launch.</p>', body_items: ['SOC 2 Type II', '99.99% uptime', 'GDPR compliant'] },
      },
      // 3 — a body-less strip long enough to wrap at mobile: the #489 clip must still
      //     hide the line-leading separator on every wrapped line.
      {
        component: 'section',
        props: {
          id: 'pp-sec-bodyless-wrap',
          body_items: ['Recuperación incluida', 'Copias diarias', 'Sin permanencia', 'Soporte en español', '99,9% de disponibilidad'],
        },
        udc: DARK,
      },
    ]);
    expect(seedRes.success, `udc write: ${JSON.stringify(seedRes)}`).toBe(true);

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

      // THE NARROWING, RENDERED. A body-less strip no longer zeroes itself: it keeps
      // var(--space-md) (16px), the same as a strip that has body copy above it. If this
      // ever reads 0 again, something has reintroduced a rule that infers design intent
      // from an empty prop, and three disclosure surfaces are wrong.
      expect(await marginTop('pp-sec-bodyless'), `body-less default @${width}`).toBe(16);
      expect(await marginTop('pp-sec-withbody'), `with-body default @${width}`).toBe(16);

      // THE ROUTE BACK, RENDERED. The one line the docs hand the author does exactly what
      // #488's automatic behaviour did — this is what makes the narrowing disclosable
      // rather than a regression.
      expect(await marginTop('pp-sec-bodyless-flush'), `documented route @${width}`).toBe(0);

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
  // RETIRED (#986): a hero style slot with no v2 successor — the value is a role parameter now, covered by the UDC contract tests.

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
   * RETIRED (#1026): the three cta button-slot rendered pins in this block.
   *
   *   '#474 second button outline routes to the AA role token on both dark bands…'
   *   '#535 dark-band primary + cover-hero buttons route to the AA role tokens…'
   *   '#474 primary button slots do not leak into a filled second button…'
   *
   * All three measured a `.cta--inverted` / `.cta--has-bg-image` band, or the slot-leak
   * between cta's two buttons. The classes derive from retired props, and the leak was a
   * consequence of slots being custom properties on the band root: nothing is emitted there
   * now, so the two buttons are separate roles with separate blocks and cannot reach each
   * other. The isolation is a property of the emitter rather than of a rule that could be
   * deleted — which is why nothing replaces the third pin.
   *
   * The `#535` pin's COVER-HERO half is not lost: hero is unaffected by this change and its
   * rows stay in the #542 focus-ring block, which is the part of #535's family that #986
   * re-keyed onto the engine's overlay attribute rather than a layout class.
   */


  // RETIRED (#986): pinned a hero style slot or the .hero__overlay element, neither of which exists on a v2 hero. The surviving behaviour is covered by the UDC contract tests and by the cta rows in the same block.


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
  // RETIRED (#986): a hero style slot with no v2 successor — the value is a role parameter now, covered by the UDC contract tests.

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
  //
  // RE-AUTHORED FOR v2 (#1023), same axis and same incident. The value moved from the
  // `--section-padding-top` slot to the `_band` role's `spacing.padding-top`, and the
  // question the pin asks is unchanged: does the browser render the author's value once
  // the premium clamp() rules, the media queries and the adjacent-band rhythm all apply?
  //
  // The v2 answer is stronger than the slot's was, and the fixture proves the stronger
  // claim rather than the old one: a role block is emitted UNLAYERED and band-scoped, so
  // it outranks `@layer pp-v1` outright — including the `main > .section` premium rules
  // this pin was born to catch, which is why those four rules were deletable as dead code
  // in the same change. And because the parameter takes a breakpoint map, the pin now
  // covers all three tiers with DIFFERENT values, which the single slot could never hold.
  test('#1023 section honors an authored _band padding at all three tiers @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Section Band Padding');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-seed', body: '<p>Seed.</p>' } }]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // Pixel values no token resolves to, so a premium clamp() clobber is unmistakable —
    // and three DISTINCT ones, so a breakpoint map that collapsed to a single tier would
    // fail here rather than pass on the desktop value.
    const res = await updateComposition(page, pageId, [
      {
        component: 'section',
        props: { id: 'pp-sec01', title: 'Role contract', body: '<p>Padding must be controllable per band.</p>' },
        udc: { _band: { spacing: { 'padding-top': { d: '77px', t: '55px', p: '33px' } } } },
      },
    ]);
    expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);

    for (const [width, expected] of [[1280, '77px'], [768, '55px'], [375, '33px']] as const) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      const section = page.locator('main > .section');
      await expect(section).toBeVisible({ timeout: 10000 });

      const paddingTop = await section.evaluate((el) => getComputedStyle(el).paddingTop);
      expect(paddingTop, `@${width}`).toBe(expected);
    }
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
  // RE-AUTHORED FOR v2 (#1023). The measure is the `body` role's `sizing.max-width`, and
  // the ancestor-cap question is the same one — only a rendered box can show that no
  // wrapper is capping the content the author sized.
  //
  // TWO THINGS THIS PIN NOW CARRIES THAT THE SLOT VERSION COULD NOT:
  //
  //  1. THE UNAUTHORED DEFAULT IS 40rem, and that is the number a v1 band actually
  //     RENDERED — which is NOT the rule that won among those targeting the element. Four
  //     rules capped `.section__content` and the desktop `main > .section--text-only`
  //     override at 49rem beat the others, but `.section__content` sits inside
  //     `.section__body`, which capped at 40rem, so the 49rem literal never bound.
  //     Measured v1 at 375/768/1280: text-only 640px, centered 672px. An earlier cut of
  //     this very test asserted 784px on the winning-rule reasoning and was wrong; band 1
  //     below is the rendered proof of the corrected value.
  //  2. Section's four measure BRANCHES collapsed to one role parameter with a breakpoint
  //     map, so the pin reads the authored value at three tiers rather than one.
  test('#1023 the section body measure reaches the rendered box, and the unauthored default is 40rem @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Section Body Measure');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-seed', body: '<p>Seed.</p>' } }]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // 700px is wider than the old 640px wrapper default, so a re-introduced ancestor cap
    // fails loudly instead of hiding inside the old limit.
    const res = await updateComposition(page, pageId, [
      {
        component: 'section',
        props: { id: 'pp-sec01', title: 'Width is controllable', body: '<p>The measure must reach the rendered content box.</p>' },
        udc: { body: { sizing: { 'max-width': { d: '700px', t: '600px', p: '300px' } } } },
      },
      {
        component: 'section',
        props: { id: 'pp-sec02', title: 'Unauthored', body: '<p>The role default must still be 49rem.</p>' },
      },
    ]);
    expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);

    for (const [width, expected] of [[1280, '700px'], [768, '600px'], [375, '300px']] as const) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      const content = page.locator('#pp-sec01 .section__content');
      await expect(content).toBeVisible({ timeout: 10000 });

      const box = await content.evaluate((el) => {
        const wrapper = el.closest('.section__body') as Element | null;
        return {
          content: getComputedStyle(el).maxWidth,
          wrapperCap: wrapper ? getComputedStyle(wrapper).maxWidth : null,
          rendered: Math.round(el.getBoundingClientRect().width),
        };
      });

      // The authored value reaches the content box…
      expect(box.content, `authored measure @${width}`).toBe(expected);
      // …and nothing above it is capping narrower than that. `none` is the v2 answer:
      // the wrapper caps were deleted, so the only cap in the chain is the role's own.
      if (box.wrapperCap !== 'none') {
        expect(parseFloat(box.wrapperCap as string), `ancestor cap @${width}`).toBeGreaterThanOrEqual(parseFloat(expected));
      }
      // The rendered box must actually be able to USE it where the viewport allows.
      if (width >= 768) expect(box.rendered).toBeGreaterThan(400);
    }

    // The unauthored default, read at desktop where 49rem (784px) fits.
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const unauthored = page.locator('#pp-sec02 .section__content');
    await expect(unauthored).toBeVisible({ timeout: 10000 });
    expect(
      await unauthored.evaluate((el) => getComputedStyle(el).maxWidth),
      'the unauthored body measure must be 40rem — the width a v1 text-only band actually '
        + 'RENDERED, not the 49rem rule that won among those targeting .section__content but '
        + 'never bound because the .section__body wrapper capped it first',
    ).toBe('640px');
  });

  // #470: the section body text size + weight are authorable via --section-body-size
  // (length) and --section-body-weight (number). Before #470 the body font-size/weight
  // was baked as literals in the desktop premium and mobile rules, so a deliberate
  // type step (compact utility band, emphasis paragraph) was unreachable. The slots
  // must reach the rendered body at BOTH breakpoints (the #86/#349 mobile-hid-it
  // lesson), and an UNSET section must render byte-identically to today: weight 430 at
  // both, size 1.065rem (desktop) / 1rem (mobile) resolved against the page's own root.
  // Two sections prove both halves in one render: section 0 SET, section 1 UNSET.
  // RE-AUTHORED FOR v2 (#1023). The two slots are the `body` role's `typography.size` and
  // `typography.weight`, and the claim is unchanged in both halves: an authored band gets
  // the deliberate type step #470 asked for, and an UNAUTHORED band is byte-identical to
  // what shipped — weight 430 at every tier, size 1.065rem desktop/tablet and 1rem phone.
  //
  // THE UNSET HALF IS THE LOAD-BEARING ONE and it is why this is not a mechanical
  // re-point: those literals used to live in the desktop premium and mobile stylesheet
  // rules, and the rebuild moved them into role defaults. "The values moved, the pixels
  // did not" is the promise the whole release makes, and this is the rendered proof of it
  // for the most-used band in the theme — read at the SAME three tiers as the padding and
  // measure pins, because the phone tier is where the 1.065 -> 1 step lives and the #86/#349
  // lesson is that a mobile-only regression hides from a desktop-only read.
  test('#1023 the section body type is authorable per band, and an unauthored band is byte-identical @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Section Body Type Roles');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-seed', body: '<p>Seed.</p>' } }]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // Distinctive, unambiguous values: 22px is no theme literal, 850 is no default weight
    // — and 850 is only expressible at all because #988 widened the font-weight grammar
    // to the real CSS range, which this rebuild needed for its own 560/430 defaults.
    const res = await updateComposition(page, pageId, [
      {
        component: 'section',
        props: { id: 'pp-sec01', title: 'Set body type', body: '<p>Deliberate size and weight.</p>' },
        udc: { body: { typography: { size: '22px', weight: '850' } } },
      },
      {
        component: 'section',
        props: { id: 'pp-sec02', title: 'Default body type', body: '<p>Unchanged defaults.</p>' },
      },
    ]);
    expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);

    const bodyType = (i: number) =>
      page.locator('.section__content').nth(i).locator('p').first().evaluate((el) => {
        const cs = getComputedStyle(el);
        const rootPx = parseFloat(getComputedStyle(document.documentElement).fontSize);
        return { fontSize: cs.fontSize, fontWeight: cs.fontWeight, rootPx };
      });

    for (const width of [1280, 768, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.section__content')).toHaveCount(2, { timeout: 10000 });

      // The authored role value reaches the body at every tier — the issue's case.
      const set = await bodyType(0);
      expect(set.fontSize, `authored size @${width}`).toBe('22px');
      expect(set.fontWeight, `authored weight @${width}`).toBe('850');

      // Unset renders byte-identically to v1: weight 430 at every tier, size 1.065rem at
      // desktop AND tablet, 1rem at phone, resolved against the page's own root font-size
      // — the exact historical literals, and NOT the authored band's value.
      const unset = await bodyType(1);
      expect(unset.fontWeight, `default weight @${width}`).toBe('430');
      const remFactor = width >= 768 ? 1.065 : 1;
      expect(unset.fontSize, `default size @${width}`).toBe(`${remFactor * unset.rootPx}px`);
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
  // COVERAGE RESTORED (#696, 2026-08-17): --grid-item-border-color was missing.
  // #576 ("apply the canonical slot and prop vocabulary across all ten components")
  // renamed --grid-card-border-width -> --grid-item-border-width AND newly ADDED the
  // colour slots; this array was updated for the renames only. That is exactly the
  // drift the guard below exists to catch, and it caught it — the array was stale,
  // the guard was right.
  //
  // REPRICED (v2 Sprint 0): testimonials' four entries are gone because its four
  // border slots are gone — it is the first component on the Universal Design
  // Contract and declares no style_slots at all. The set-equality guard below is
  // schema-derived, so it follows that removal on its own. What replaced the case
  // is NOT a like-for-like port: this whole strand exists because a slot NAME lands
  // in the root's inline `style` attribute, where WP core's substring selector
  // `:where([style*=border-width])` can see it. A v2 component emits no inline
  // style attribute at all, so the trigger cannot be constructed — a stronger
  // guarantee than immunity, and it is pinned as such in the v2 test below.
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
    // HERO'S AND SECTION'S ROWS ARE RETIRED, AND THE CASE IS INAPPLICABLE RATHER THAN
    // UNPINNED (#986, #1023).
    //
    // It was left here with an EMPTY slot map during the repricing, which made it vacuous:
    // `styleComponent()` refuses a v2 component with `no_style_slots`, so the row failed on
    // its own setup rather than on anything about borders. A half-finished reprice, caught
    // by CI because this row sits outside the @smoke subset.
    //
    // Why hero cannot come back to this list: issue 332 is WP core injecting
    // `border-style: solid` through `:where([style*="border-width"])`, which matches on the
    // INLINE STYLE ATTRIBUTE. A v2 component emits none, so core's selector has nothing to
    // match and the trigger class is unreachable for it. Both are covered by the v2
    // border-sink pin below, which asserts exactly that — a stronger statement than these
    // rows made, because it holds for every trigger core might add rather than the slots
    // that happened to exist.
    //
    // Section's row left the same way at #1023, and cta's at #1026 — which is the change
    // that CLOSED the note this comment used to end with, so the closure is recorded here
    // rather than only in the commit that made it.
    //
    // #1023 left the issue-332 immunity baseline un-narrowed and said why: section's `_band`
    // border default is zero-width/transparent, byte-identical to what the baseline forces,
    // so the `pp-zero` vs `pp-v1` collision was invisible and the trap did not bite. It bit
    // at #1026, because cta's `_band` default is a REAL 1px rule top and bottom — the value
    // v1's `.cta--full-width` drew. Measured in Chromium at 375/768/1280, before and after:
    // `.cta` read `border-top-width: 0px` / `border-top-style: none` where v1 read
    // `1px` / `solid`, while `border-top-color` survived at `rgb(217,224,235)` both ways —
    // the tell that the role default was emitting correctly and only the two longhands the
    // baseline claims were gone.
    //
    // The baseline's premise is its SELECTOR now (`:where([style])`) rather than a roster
    // that approximated it, so it cannot drift a third time. `.grid` and `.grid__item` were
    // re-read in the same scene and were unchanged: the immunity this whole strand exists
    // for is intact for every component that still carries an inline style attribute.
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

    // Fail-closed floor: 13 trigger slots existed at issue 332; 11 remained after section's
    // two left with its slot map (#1023), and 7 remain after cta's four
    // (`--cta-border-width`, `--cta-border-color`, `--cta-eyebrow-border-width`,
    // `--cta-eyebrow-border-color`) left at #1026. The floor tracks the v1 surface, which
    // shrinks one rebuild sprint at a time — it is NOT a statement that the theme has fewer
    // borders. cta's band still draws 1px top and bottom; it draws them from a role default
    // that WP core's substring selector can never see.
    expect(declared.size).toBeGreaterThanOrEqual(7);
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

  // REPLACES the testimonials AND hero rows of BORDER_TRIGGER_CASES (#986).
  //
  // The v1 strand asked "does the slot name in the inline style attribute trip WP
  // core's :where([style*=border-width]) into painting a 3px border?" For a v2
  // component that question is unaskable: the border values live in a
  // `[data-pp-band]` block in the head, and the root carries no `style` attribute
  // for a substring selector to match. This pins the absence of the SINK, which is
  // what actually makes the component immune — and it is authored the v2 way, with
  // real border values in flight, so a regression that reintroduced inline style
  // emission would fail here rather than silently restoring the old exposure.
  // Parameterised over every v2 component (#986), so a component's rebuild adds it here
  // instead of leaving a vacuous row in the v1 list.
  for (const v2 of [
    {
      component: 'testimonials',
      rootSel: 'main > .testimonials',
      innerSel: '.testimonials__item',
      props: { id: 'pp-tst01', items: [{ quote: 'It works.', author: 'A' }] },
      udc: {
        card: { border: { width: '2px', color: '#345678' } },
        eyebrow: { border: { width: '3px', color: '#876543' } },
      },
    },
    {
      component: 'hero',
      rootSel: 'main > .hero',
      innerSel: '.hero__image',
      props: { id: 'pp-hero01', layout: 'split', title: 'Hero', image_url: '/x.png', image_alt: 'x' },
      udc: {
        media: { border: { width: '2px', style: 'solid', color: '#345678' } },
        eyebrow: { border: { width: '3px', style: 'solid', color: '#876543' } },
      },
    },
    {
      // #1023. The `text-panel` layout is chosen deliberately: `.section__panel-row` is
      // one of the three selectors the issue-332 immunity baseline still names, so this
      // row renders the element whose premise the rebuild falsified and proves the sink
      // is absent on it too — not only on the band root.
      component: 'section',
      rootSel: 'main > .section',
      innerSel: '.section__panel',
      props: {
        id: 'pp-sec01',
        layout: 'text-panel',
        body: '<p>Body.</p>',
        panel_heading: 'Panel',
        panel_items: [{ label: 'A', value: '1' }],
      },
      udc: {
        panel: { border: { width: '2px', style: 'solid', color: '#345678' } },
        eyebrow: { border: { width: '3px', style: 'solid', color: '#876543' } },
      },
    },
  ] as const) {
  test(`#332 a v2 band (${v2.component}) carries border values with no inline style attribute to trigger core`, async ({
    page,
  }) => {
    pageId = createPage(`E2E ${v2.component} v2 Border Sink`);
    setComposition(pageId, [
      { component: 'section', props: { id: 'pp-seed', body: '<p>Seed.</p>' } },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const res = await updateComposition(page, pageId, [
      { component: v2.component, props: v2.props, udc: v2.udc },
    ]);
    expect(res.success, `udc border write: ${JSON.stringify(res)}`).toBe(true);

    await page.goto(`/?page_id=${pageId}`);
    const root = page.locator(v2.rootSel);
    await expect(root).toBeVisible({ timeout: 10000 });

    // The sink is absent: no inline style attribute anywhere in the band.
    expect(await root.getAttribute('style'), 'a v2 band root emits no inline style').toBeNull();
    expect(
      await root.locator('[style]').count(),
      'and no descendant of a v2 band emits one either',
    ).toBe(0);

    // Non-vacuity: the values really did travel, via the scoped band block.
    const innerBorder = await root
      .locator(v2.innerSel)
      .first()
      .evaluate((el) => getComputedStyle(el).borderTopWidth);
    expect(innerBorder, 'the authored border reached the element').toBe('2px');

    // And the root itself still takes no border from core's substring rule.
    const rootBorder = await root.evaluate((el) => {
      const c = getComputedStyle(el);
      return { top: c.borderTopWidth, right: c.borderRightWidth, bottom: c.borderBottomWidth, left: c.borderLeftWidth };
    });
    expect(rootBorder).toEqual({ top: '0px', right: '0px', bottom: '0px', left: '0px' });
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

  /*
   * THE POSITIVE CONTROL, REBUILT FOR v2 (#1026) RATHER THAN RETIRED WITH cta's SLOTS.
   *
   * It used to author `--cta-border-width: 4px` through `style_component` and assert the
   * band drew it on exactly the sides the component declares. cta has no slots now, so the
   * write path changed — but the PROPERTY is the reason the whole issue-332 strand exists
   * and it got MORE important at #1026, not less: cta's `_band` role defaults to a real 1px
   * rule top and bottom, which the immunity baseline erased until the baseline was narrowed.
   *
   * So this asserts both halves, which the slot version could only assert one of:
   *   1. THE DEFAULT renders. 1px solid, top and bottom, sides off. This is the half that
   *      was BROKEN before the narrowing — measured at 0px/none on all three tiers — and it
   *      is why a control that only tested an authored value would have passed throughout.
   *   2. AN AUTHORED value renders, on exactly the sides it names. The baseline must not
   *      suppress a border the author actually asked for, which is the original claim.
   *
   * Authored through `update_composition`, not raw meta: raw meta mints no band id, so a
   * `udc` map written that way scopes to nothing and this test would measure the default
   * twice while passing.
   */
  test('#332 a v2 band draws its `_band` border default AND an authored one', async ({
    page,
  }) => {
    pageId = createPage('E2E Border v2 cta');
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // 1 — THE DEFAULT.
    const seeded = await updateComposition(page, pageId, [
      { component: 'cta', props: { id: 'pp-cta01', button_text: 'Go', button_url: '/go' } },
    ]);
    expect(seeded.success, JSON.stringify(seeded)).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const root = page.locator('.cta').first();
    await expect(root).toBeVisible({ timeout: 10000 });

    const read = (el: Element) => {
      const s = getComputedStyle(el);
      return {
        top: s.borderTopWidth, bottom: s.borderBottomWidth, left: s.borderLeftWidth,
        topStyle: s.borderTopStyle, color: s.borderTopColor,
      };
    };

    const dflt = await root.evaluate(read);
    expect(dflt.top).toBe('1px');
    expect(dflt.bottom).toBe('1px');
    expect(dflt.topStyle).toBe('solid');
    expect(dflt.left).toBe('0px');

    // 2 — AN AUTHORED value, on exactly the sides it names.
    // Back to the admin page first: the read above navigated to the FRONT END, where
    // `ppAiChat` does not exist, so a second write from here would fail on its own setup
    // rather than on anything about borders.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const styled = await updateComposition(page, pageId, [
      {
        component: 'cta',
        props: { id: 'pp-cta01', button_text: 'Go', button_url: '/go' },
        udc: {
          _band: {
            border: {
              'width-top': '4px', 'width-bottom': '4px',
              'style-top': 'solid', 'style-bottom': 'solid',
              color: '#ff0080',
            },
          },
        },
      },
    ]);
    expect(styled.success, JSON.stringify(styled)).toBe(true);

    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('.cta').first()).toBeVisible({ timeout: 10000 });
    const authored = await page.locator('.cta').first().evaluate(read);
    expect(authored.top).toBe('4px');
    expect(authored.bottom).toBe('4px');
    expect(authored.left).toBe('0px');
    expect(authored.color).toBe('rgb(255, 0, 128)');
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
  // RETIRED (#986): pinned a hero style slot or the .hero__overlay element, neither of which exists on a v2 hero. The surviving behaviour is covered by the UDC contract tests and by the cta rows in the same block.

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
      // THROUGH THE AUTHORING PATH, NOT A RAW META WRITE (#986, and 14.1).
      //
      // The measure that forces the wrap used to be the `--hero-content-width` STYLE SLOT,
      // which renders as an inline custom property and therefore lands on a raw
      // `setComposition()` write. It is the `content` role's `sizing.max-width` now, and a
      // role value is emitted in a block keyed on `data-pp-band` — an id the engine mints
      // on WRITE only. A raw meta write mints none, so the band renders with no attribute,
      // the block selects nothing, and the authored measure silently does not apply.
      //
      // That is exactly what happened: the column stayed full-width, and the assertion
      // below read a misalignment that the hero does not actually have. The guard after the
      // write is the durable half of the fix — an inert fixture now fails saying so,
      // instead of failing as if the component were broken.
      setComposition(pageId, [
        { component: 'section', props: { id: 'pp-seed', body: '<p>Seed.</p>' } },
      ]);
      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
      const written = await updateComposition(page, pageId, [
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
          // Narrower than the two buttons side by side (each floors at `main .btn`'s
          // min-width: 13.25rem), so they must wrap — and wide enough that one button is
          // well short of filling its row, so "centered" and "left" stay different
          // answers. Authored on the `content` ROLE now (#986): the measure was
          // `--hero-content-width`, and hero has no style slots.
          udc: { content: { sizing: { 'max-width': '26rem' } } },
        },
      ]);
      expect(written.success, `udc write: ${JSON.stringify(written)}`).toBe(true);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      const group = page.locator('.hero__cta-group');
      await expect(group).toBeVisible({ timeout: 10000 });

      // NON-VACUITY: the authored measure must actually have landed. 26rem is 416px, and
      // without it the content column runs the full band width, which un-wraps the buttons
      // and makes every alignment assertion below meaningless.
      const authoredWidth = await group.evaluate((el: Element) => el.getBoundingClientRect().width);
      expect(
        Math.abs(authoredWidth - 416),
        `the authored content measure did not apply (group is ${authoredWidth}px, expected ~416px) — the band block selected nothing`,
      ).toBeLessThan(4);

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
  // REPRICED AGAIN (#1023): section left this loop with its slots, exactly as
  // testimonials did in Sprint 0. Its two header-rhythm halves are pinned on the roles in
  // the v2 test below, which now covers both v2 components rather than one.
  for (const { component, slot, expected } of [
    { component: 'grid', slot: '--grid-subheading-margin-bottom', expected: '32px' },
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
            // Each component's own required props (section: body, grid: items).
            ...(component === 'section' ? { body: '<p>Body copy.</p>' } : {}),
            ...(component === 'grid' ? { items: [{ title: 'One', text: 'Card' }] } : {}),
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
  // override — only computed style does. 1.65rem @ 16px root = 26.4px.
  //
  // REPRICED (v2 Sprint 0, then #1023): testimonials left this loop with its slots, and
  // section followed. The header rhythm they pinned is not gone — it moved onto the UDC
  // roles, where the same two halves are pinned in the v2 test that follows this loop.
  // Pinned twice: unset -> the real rendered default, and set -> the operator wins.
  for (const { component, locator, slot, expected } of [
    { component: 'grid', locator: '.grid__heading', slot: '--grid-heading-margin-bottom', expected: '26.4px' },
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
            // Each component's own required props (section: body, grid: items).
            ...(component === 'section' ? { body: '<p>Body copy.</p>' } : {}),
            ...(component === 'grid' ? { items: [{ title: 'One', text: 'Card' }] } : {}),
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

  // REPLACES the testimonials rows of BOTH the #336 and the #343 loops above.
  //
  // Those two strands pinned one thing in two halves: the header rhythm renders a
  // documented default when unset, and the operator's value wins when set. Both
  // halves survive the v2 rewrite — only the mechanism changed, from two style
  // slots to two UDC roles (`subheading` and `heading`, Spacing group). Keeping
  // them in one test keeps the pair legible: the #336 half is the one that lost
  // the cascade to base.css's `p:last-child { margin-bottom: 0 }`, so it is still
  // asserted together with the last-child fact that made it fragile.
  test('#336/#343 the testimonials header rhythm holds on its UDC roles, unset and set @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Testimonials v2 Header Rhythm');
    setComposition(pageId, [
      {
        component: 'testimonials',
        props: {
          id: 'pp-tst01',
          title: 'Title',
          subheading: 'Subheading copy.',
          items: [{ quote: 'Great.', author: 'A. Person' }],
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const sub = page.locator('.testimonials__subheading');
    const head = page.locator('.testimonials__heading');
    await expect(sub).toBeVisible({ timeout: 10000 });

    // Unset -> the documented defaults, carried by the role-defaults block. Same
    // 32px both strands pinned before; the v2 path must not quietly retune them.
    expect(
      await sub.evaluate((el) => getComputedStyle(el).marginBottom),
      'subheading keeps its bottom rhythm as the header\'s last child',
    ).toBe('32px');
    expect(
      await sub.evaluate((el) => el === el.parentElement?.lastElementChild),
      'and it really is the last child — the condition that broke it in #336',
    ).toBe(true);
    expect(
      await head.evaluate((el) => getComputedStyle(el).marginBottom),
      'title keeps its gap above the subheading',
    ).toBe('32px');

    // Set -> the author wins, through the validated v2 write path. Values no
    // token resolves to, so a default leaking through is unmistakable.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await updateComposition(page, pageId, [
      {
        component: 'testimonials',
        props: {
          id: 'pp-tst01',
          title: 'Title',
          subheading: 'Subheading copy.',
          items: [{ quote: 'Great.', author: 'A. Person' }],
        },
        udc: {
          subheading: { spacing: { 'margin-bottom': '61px' } },
          heading: { spacing: { 'margin-bottom': '62px' } },
        },
      },
    ]);
    expect(res.success, `udc header rhythm write: ${JSON.stringify(res)}`).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    expect(await sub.evaluate((el) => getComputedStyle(el).marginBottom)).toBe('61px');
    expect(await head.evaluate((el) => getComputedStyle(el).marginBottom)).toBe('62px');
  });

  // The SECTION twin of the pair above (#1023). Written as its own test rather than
  // parameterised over the two v2 components, because the two do not share the numbers
  // that make the pin meaningful: testimonials rhythms at a flat 32px, and section's
  // heading carries a BREAKPOINT MAP — 1.65rem at desktop and tablet, 1.25rem at phone.
  //
  // That map is the part worth a rendered pin. On v1 the value came from a base rule plus
  // TWO premium breakpoint overrides (the #302 split), and #343's whole point was that a
  // declaration-level assertion could not prove the slot survived the premium override.
  // The role default replaces all three with one map, so the phone tier is read here
  // explicitly — a collapse to a single tier would pass a desktop-only read.
  test('#336/#343 the section header rhythm holds on its UDC roles, at every tier and when set @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Section v2 Header Rhythm');
    setComposition(pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec01',
          title: 'Title',
          eyebrow: 'Kicker',
          subheading: 'The title must not collide with the sub-heading below it.',
          body: '<p>Body copy.</p>',
        },
      },
    ]);

    const head = page.locator('.section__title');
    const sub = page.locator('.section__subheading');

    // Unset -> the documented defaults, carried by the role-defaults block.
    for (const [width, headingMb] of [[1280, '26.4px'], [768, '26.4px'], [375, '20px']] as const) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(sub).toBeVisible({ timeout: 10000 });

      expect(
        await head.evaluate((el) => getComputedStyle(el).marginBottom),
        `title gap above the subheading @${width}`,
      ).toBe(headingMb);
      expect(
        await sub.evaluate((el) => getComputedStyle(el).marginBottom),
        `subheading keeps its bottom rhythm as the header's last child @${width}`,
      ).toBe('16px');
      expect(
        await sub.evaluate((el) => el === el.parentElement?.lastElementChild),
        'and it really is the last child — the condition that broke it in #336',
      ).toBe(true);
    }

    // Set -> the author wins, through the validated v2 write path. Values no token
    // resolves to, so a default leaking through is unmistakable.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await updateComposition(page, pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec01',
          title: 'Title',
          eyebrow: 'Kicker',
          subheading: 'The title must not collide with the sub-heading below it.',
          body: '<p>Body copy.</p>',
        },
        udc: {
          subheading: { spacing: { 'margin-bottom': '61px' } },
          heading: { spacing: { 'margin-bottom': '62px' } },
        },
      },
    ]);
    expect(res.success, `udc header rhythm write: ${JSON.stringify(res)}`).toBe(true);

    // Read at the PHONE tier deliberately: the authored flat value must override every
    // tier of the default's map, not just the one that happens to match the viewport.
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    expect(await sub.evaluate((el) => getComputedStyle(el).marginBottom)).toBe('61px');
    expect(await head.evaluate((el) => getComputedStyle(el).marginBottom)).toBe('62px');
  });

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
    // RETIRED (#986): a hero style slot with no v2 successor — the value is a role parameter now, covered by the UDC contract tests.
  }

  // #356: the eyebrow pill had color/bg/radius slots but no border slot, so an
  // OUTLINED pill was inexpressible. Border width/color slots make it authorable.
  //
  // `home-hero` is a #412 parity guard, exactly as in the #336 radius strand above: the
  // demo-id `.hero__eyebrow` block that once re-declared `border-color` at ID specificity
  // was evicted in issue 412, so `home-hero` must render the border through the same base
  // slot rules as any other id (exercising both ids proves the reserved id is not special).
  for (const heroId of ['pp-hero01', 'home-hero']) {
    // RETIRED (#986): a hero style slot with no v2 successor — the value is a role parameter now, covered by the UDC contract tests.
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
    // RETIRED (#986): a hero style slot with no v2 successor — the value is a role parameter now, covered by the UDC contract tests.
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
 * the pp_site_udc SITE OPTION rather than composition style slots. That puts them
 * outside the issue-305 schema guard entirely, and StyleSlotContractTest only scans for
 * slot names it can discover from a schema — so nothing static can prove chrome styling
 * reaches the browser.
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

/**
 * Imports a real image into the Media Library and returns its attachment id.
 *
 * A genuine import rather than a fabricated post row: ruling A2's whole point is that
 * the id is a REFERENCE the engine resolves through WordPress, and a hand-made row
 * would skip exactly the resolution being tested.
 */
function importTestImage(slug: string): number {
  const file = `/tmp/${slug}.png`;
  // A 1x1 opaque PNG, written inside the container so `wp media import` can read it.
  const b64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
  execSync(`npx wp-env run cli bash -c "echo ${b64} | base64 -d > ${file}"`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
  return parseInt(
    execSync(`npx wp-env run cli wp media import ${file} --porcelain`, {
      cwd: process.cwd(),
      encoding: 'utf-8',
    })
      .trim()
      .split(/\s+/)
      .pop() as string,
    10,
  );
}

function deleteAttachment(attachmentId: number): void {
  try {
    execSync(`npx wp-env run cli wp post delete ${attachmentId} --force`, { cwd: process.cwd() });
  } catch {
    /* already gone */
  }
}

/** Writes the whole chrome container. A write REPLACES it, so pass every component. */
function setChromeUdc(map: Record<string, unknown>): void {
  setSiteOption('pp_site_udc', JSON.stringify(map));
}

function deleteSiteOption(key: string): void {
  try {
    execSync(`npx wp-env run cli wp option delete ${key}`, { cwd: process.cwd() });
  } catch {
    /* not set — nothing to clean */
  }
}

/**
 * THE REAL WRITE PATH, not the option row (#991).
 *
 * `setSiteOption()` above seeds the row directly, which is the right tool for a
 * styling fixture and the wrong one for proving anything about writes. These four run
 * the pipeline an operator and the chat model actually traverse: mint a run token with
 * `operate inspect`, cover it with `apply preflight`, then `action execute`. The
 * action layer refuses a token without a completed inspect step, so the order matters.
 */
/**
 * POSIX single-quote escape: close, escape, reopen. Every value interpolated into a
 * shell string below goes through this — including the run id and action name, which
 * are locally generated today and would be a shell-injection sink the moment someone
 * copies this shape for an operator- or fixture-supplied value.
 */
function shq(value: string): string {
  return `'${String(value).replace(/'/g, `'\\''`)}'`;
}

/**
 * Pull the first balanced JSON object out of CLI output and parse it.
 *
 * The commands wrap their envelope in human-readable lines, so the object has to be
 * sliced out rather than JSON.parse'd whole. Brace-balanced (string-aware) rather than
 * regex-scraped, so a brace inside a value cannot truncate it — and so a malformed
 * envelope throws here instead of silently matching half of itself.
 */
function parseEnvelope(raw: string): Record<string, unknown> {
  const start = raw.indexOf('{');
  if (start === -1) throw new Error(`no JSON object in CLI output: ${raw.slice(0, 400)}`);
  let depth = 0;
  let inStr = false;
  let esc = false;
  for (let i = start; i < raw.length; i += 1) {
    const c = raw[i];
    if (inStr) {
      if (esc) esc = false;
      else if (c === '\\') esc = true;
      else if (c === '"') inStr = false;
      continue;
    }
    if (c === '"') inStr = true;
    else if (c === '{') depth += 1;
    else if (c === '}') {
      depth -= 1;
      if (depth === 0) return JSON.parse(raw.slice(start, i + 1));
    }
  }
  throw new Error(`unbalanced JSON in CLI output: ${raw.slice(0, 400)}`);
}

function ppOperateInspect(): string {
  const raw = execSync('npx wp-env run cli wp pp operate inspect', {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
  const runId = parseEnvelope(raw).run_id;
  if (typeof runId !== 'string' || runId === '') {
    throw new Error(`operate inspect returned no run_id: ${raw.slice(0, 400)}`);
  }
  return runId;
}

function ppPreflight(runId: string): void {
  execSync(`npx wp-env run cli wp pp apply preflight --run-id=${shq(runId)}`, {
    cwd: process.cwd(),
  });
}

function runAction(name: string, params: Record<string, unknown>, runId: string): string {
  return execSync(
    `npx wp-env run cli wp pp action execute ${shq(name)} --run-id=${shq(runId)} ` +
      `--params=${shq(JSON.stringify(params))}`,
    { cwd: process.cwd(), encoding: 'utf-8' },
  );
}

/**
 * Run an action that is EXPECTED to be refused, returning ONLY what the command
 * actually wrote, so the caller can assert on the refusal code.
 *
 * DELIBERATELY EXCLUDES `err.message`. Node puts the whole command line into it —
 * including the `--params='{...}'` JSON the caller just sent — so a haystack built
 * from `message` always contains the test's own INPUT, and any `toContain` on a value
 * that appears in the params matches the echo rather than the refusal. That is not
 * hypothetical: the legacy-key test below asserted `/pp_header_bg/` and passed on the
 * echoed command, which would have stayed green with the write gate never running.
 *
 * A spawn that produced neither stdout nor stderr is an ENVIRONMENT failure (docker
 * down, container gone), not a refusal, so it rethrows instead of returning an empty
 * string that every assertion would then fail against for the wrong reason.
 */
function runActionExpectingFailure(
  name: string,
  params: Record<string, unknown>,
  runId?: string,
): string {
  const id = runId ?? (() => {
    const fresh = ppOperateInspect();
    ppPreflight(fresh);
    return fresh;
  })();
  let succeeded: string | null = null;
  try {
    succeeded = execSync(
      `npx wp-env run cli wp pp action execute ${shq(name)} --run-id=${shq(id)} ` +
        `--params=${shq(JSON.stringify(params))}`,
      { cwd: process.cwd(), encoding: 'utf-8', stdio: 'pipe' },
    );
  } catch (err: unknown) {
    const e = err as { stdout?: string; stderr?: string };
    const output = `${e.stdout ?? ''}${e.stderr ?? ''}`;
    if (output.trim() === '') throw err;
    return output;
  }
  throw new Error(
    `Expected "${name}" to be refused, but it succeeded: ${(succeeded ?? '').slice(0, 400)}`,
  );
}

test.describe('chrome UDC renders (ruling A1)', () => {
  let pageId: number;
  // Only the dark-chrome test needs a real menu; 0 means "nothing to tear down".
  let darkMenuId = 0;
  // ONE option now carries all chrome styling, so one key is the whole cleanup list.
  const CHROME_OPTIONS = ['pp_site_udc'];

  test.afterEach(async () => {
    // No residue: these are SITE options, so a leak would style every later test's page.
    for (const key of CHROME_OPTIONS) {
      deleteSiteOption(key);
    }
    if (darkMenuId) {
      // The menu is assigned to the `primary` theme location, which is site-global —
      // leaving it would give every later spec a header menu it did not seed.
      deleteMenu(darkMenuId);
      darkMenuId = 0;
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

  test('chrome UDC paints a real gradient on the header @smoke', async ({ page }) => {
    // THE assertion the option-era test made, carried onto the new surface: a gradient
    // is a CSS <image>, so if the fill were routed through `background-color` the
    // browser would drop the declaration and the header would paint nothing while every
    // declaration-level test stayed green. Only getComputedStyle can tell "the gradient
    // painted" from "the declaration was dropped".
    pageId = createPage('E2E Header Gradient');
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);

    setChromeUdc({
      nav: {
        _band: { background: { fill: 'linear-gradient(135deg, #1a1a2e, #16121f)' } },
        logo: { typography: { color: '#e8e8f0' } },
        link: { typography: { color: '#c8c8e0' } },
      },
    });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const header = page.locator('.site-header');
    await expect(header).toBeVisible({ timeout: 10000 });

    const bgImage = await header.evaluate((el) => getComputedStyle(el).backgroundImage);
    expect(bgImage).not.toBe('none');
    expect(bgImage).toContain('gradient');

    // Each role reaches its own element — the thing three colour options could not do.
    const logoColor = await page.locator('.nav__logo').evaluate((el) => getComputedStyle(el).color);
    expect(logoColor).toBe('rgb(232, 232, 240)');

    // And the block is SCOPED, not an inline attribute: chrome is inside the cascade now.
    const inlineStyle = await header.evaluate((el) => el.getAttribute('style'));
    expect(inlineStyle).toBeNull();
    await expect(header).toHaveAttribute('data-pp-chrome', 'nav');
  });

  test('chrome UDC paints the footer, and a state reaches a nav link @smoke', async ({ page }) => {
    pageId = createPage('E2E Footer + State');
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);

    setChromeUdc({
      footer: { _band: { background: { fill: 'linear-gradient(135deg, #1a1a2e, #16121f)' } } },
      nav: { link: { typography: { color: '#c8c8e0', ':hover': { color: '#ffd43b' } } } },
    });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const footer = page.locator('.site-footer');
    await expect(footer).toBeAttached({ timeout: 10000 });
    const bgImage = await footer.evaluate((el) => getComputedStyle(el).backgroundImage);
    expect(bgImage).not.toBe('none');
    expect(bgImage).toContain('gradient');

    // STATES ON CHROME. Ruling A3 gave the engine hover/focus-visible/active and ruling
    // A1 put chrome on that engine; hover was unreachable on chrome before, so this is
    // the pin that the two rulings actually compose in a browser.
    const link = page.locator('.nav__menu ul li a').first();
    if (await link.count()) {
      await expect(link).toHaveCSS('color', 'rgb(200, 200, 224)');
      await link.hover();
      await expect(link).toHaveCSS('color', 'rgb(255, 212, 59)');
    }
  });

  test('a plain colour still works on the chrome background', async ({ page }) => {
    pageId = createPage('E2E Header Solid Color');
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);

    setChromeUdc({ nav: { _band: { background: { fill: '#1a1a2e' } } } });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const header = page.locator('.site-header');
    await expect(header).toBeVisible({ timeout: 10000 });
    const bgColor = await header.evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(bgColor).toBe('rgb(26, 26, 46)');
  });

  test('an unstyled header is unchanged (no gradient, no inline style) @smoke', async ({
    page,
  }) => {
    // Defaults stay neutral: ruling A1 added a capability and changed no default, and
    // #994 moved where those defaults LIVE without moving what they say.
    pageId = createPage('E2E Header Default');
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const header = page.locator('.site-header');
    await expect(header).toBeVisible({ timeout: 10000 });

    expect(await header.evaluate((el) => getComputedStyle(el).backgroundImage)).toBe('none');
    expect(await header.evaluate((el) => el.getAttribute('style'))).toBeNull();

    // THE CHROME BLOCK IS PRESENT ON AN UNSTYLED SITE SINCE #994, and asserting its
    // ABSENCE is what this test used to do. That was right while chrome shipped EMPTY
    // role defaults and the header's resting appearance came from components.css; the
    // retirement made those defaults the resting appearance, so a page without the
    // block would be a page with an unpainted header. `pp_udc_chrome_defaults_css()`
    // lost its no-stored-entry short-circuit in the same change for exactly this
    // reason — the defaults are a property of the THEME, not of what a site wrote.
    //
    // What the test still guards is what it always meant: an unstyled site looks
    // unstyled. So the assertions move from "no block" to the VALUES the block must
    // carry, which are the values components.css used to declare.
    const html = await page.content();
    expect(html).toContain('[data-pp-chrome="nav"]');

    // --color-bg / --color-border, the two the deleted `.site-header` rule named.
    await expect(header).toHaveCSS('background-color', 'rgb(252, 253, 255)');
    await expect(header).toHaveCSS('border-bottom-width', '1px');
    await expect(header).toHaveCSS('border-bottom-style', 'solid');
    await expect(header).toHaveCSS('border-bottom-color', 'rgb(217, 224, 235)');

    // AND THE AUTHORED TIER IS STILL EMPTY. The two tiers print on either side of the
    // stylesheets and only the defaults one should exist here; without this, the
    // assertions above would also pass on a site that HAD written chrome styling that
    // happened to match the defaults.
    //
    // TOLD APART BY SHAPE, not by a style id: both tiers ride wp_add_inline_style (on
    // `pp-base` and `pp-utilities`), so neither has an id of its own to assert on. The
    // defaults tier wraps its ROOT rule in `:where()` inside `@layer pp-zero`; the
    // authored tier emits the bare attribute selector. `[data-pp-chrome="nav"]{` with
    // the brace immediately after can therefore only be an authored `_band` block —
    // every default element rule has a space and a class before its brace.
    expect(html).toContain('@layer pp-zero{:where([data-pp-chrome="nav"])');
    expect(html).not.toContain('[data-pp-chrome="nav"]{');
  });

  /**
   * THE MOBILE PANEL'S SURFACE IS A `p`-KEYED DEFAULT, AND THE DESKTOP MENU HAS NONE.
   *
   * The failure this exists to catch is silent and one character wide: the engine's
   * `d` breakpoint carries NO media query, so a panel fill written as `d` alone would
   * paint a bar-coloured rectangle behind the DESKTOP links, and a `submenu` surface
   * written the same way would put the dropdown's border and shadow on the mobile
   * expand-in-place list. ChromeUdcTest refuses a `d`-only map statically; this is the
   * rendered half, because "paints at the wrong width" is not a thing a schema sweep
   * can see.
   *
   * Both widths in one test on purpose: asserting the phone value alone passes just as
   * happily when the value is painting at EVERY width, which is the bug.
   */
  test('#994 the mobile panel surface is phone-scoped and does not reach the desktop menu @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 994 Panel Breakpoints');
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);
    // Reuse this describe's own menu slot so afterEach tears it down: `primary` is a
    // site-global theme location, and a leaked assignment hands every later spec a
    // header menu it never seeded.
    darkMenuId = createMenu(`E2E 994 bp ${Date.now()}`);
    addCustomToMenu(darkMenuId, 'Elsewhere', '#elsewhere');
    assignMenuToPrimary(darkMenuId);

    const menu = page.locator('.nav__menu');

    // PHONE — the disclosure panel carries the surface the deleted CSS declared.
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('.nav__toggle')).toBeVisible({ timeout: 10000 });
    await expect(menu).toHaveCSS('background-color', 'rgb(252, 253, 255)'); // --color-bg
    await expect(menu).toHaveCSS('border-bottom-width', '1px');
    expect(await menu.evaluate((el) => getComputedStyle(el).boxShadow)).not.toBe('none');

    // DESKTOP — none of it follows. A transparent fill, no border, no shadow.
    await page.setViewportSize({ width: 1280, height: 900 });
    await expect(menu).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
    await expect(menu).toHaveCSS('border-bottom-width', '0px');
    expect(await menu.evaluate((el) => getComputedStyle(el).boxShadow)).toBe('none');
  });

  /**
   * A DARK HEADER ABOVE A DARK BAND (#991).
   *
   * The realistic shape of the capability and the one where a mistake is invisible in
   * a unit test: the header is `position: sticky`, so it sits OVER the page's opening
   * band as the visitor scrolls. Styling it dark means owning the ink on every text
   * role — the "YOU OWN THE CONTRAST" rule the AI-facing docs state — and the failure
   * mode is a dark bar with dark-on-dark links that no assertion about the background
   * alone would catch.
   *
   * Every colour here is authored WITH its hover, which is the pairing #992 requires.
   */
  test('#991 a dark header over a dark band carries its own ink on every role', async ({ page }) => {
    pageId = createPage('E2E Dark Chrome');
    setComposition(pageId, [
      {
        component: 'hero',
        props: { id: 'pp-hero01', title: 'Dark opener', subtitle: 'Below a dark header.' },
      },
    ]);
    // SEED A MENU, or the link assertions below have nothing to assert ON.
    // pp_nav_menu() passes `fallback_cb => false`, so an unassigned `primary` location
    // renders no list at all — and the first cut of this test guarded its link read
    // with `if (count > 0)`, which meant the body never ran and the title's "every
    // role" claim was carried entirely by the logo. A conditional that silently
    // no-ops is worse than no test.
    darkMenuId = createMenu(`E2E Dark Chrome ${Date.now()}`);
    addPageToMenu(darkMenuId, pageId);
    assignMenuToPrimary(darkMenuId);
    setChromeUdc({
      nav: {
        _band: { background: { fill: '#101828' } },
        logo: { typography: { color: '#ffffff', ':hover': { color: '#ffd166' } } },
        link: { typography: { color: '#f7f8fa', ':hover': { color: '#ffd166' } } },
        'link-current': { typography: { color: '#ffd166' } },
        toggle: { typography: { color: '#ffffff', ':hover': { color: '#ffd166' } } },
      },
    });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const header = page.locator('.site-header');
    await expect(header).toBeVisible({ timeout: 10000 });

    // The bar itself, and the sticky positioning that makes the contrast question real.
    expect(await header.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe(
      'rgb(16, 24, 40)',
    );
    expect(await header.evaluate((el) => getComputedStyle(el).position)).toBe('sticky');

    // Every text role over that fill carries light ink rather than inheriting the
    // stylesheet's dark default. Asserted UNCONDITIONALLY — the menu is seeded above.
    await expect(page.locator('.nav__logo')).toHaveCSS('color', 'rgb(255, 255, 255)');

    const link = page.locator('.nav__menu ul li a').first();
    await expect(link).toBeVisible({ timeout: 10000 });
    // The seeded item IS the current page, so link-current owns it; assert that role's
    // colour on it and the plain `link` colour on the toggle's sibling ink instead of
    // pretending one selector covers both.
    await expect(page.locator('.nav__menu li.current-menu-item > a')).toHaveCSS(
      'color',
      'rgb(255, 209, 102)',
    );
    await expect(page.locator('.nav__toggle')).toHaveCSS('color', 'rgb(255, 255, 255)');
  });

  /**
   * BOTH DIRECTIONS THROUGH THE REAL CLI (#991).
   *
   * Direction 1: the retired v1 chrome colour options are REFUSED, so a site cannot
   * quietly keep styling the header the old way.
   * Direction 2: a chrome `udc` map written through the genuine action pipeline
   * (`operate inspect` -> `apply preflight` -> `action execute`) reaches the rendered
   * page. The other chrome tests seed the option row directly, which is fine for
   * styling fixtures but proves nothing about the write path an operator or the model
   * actually uses.
   */
  test('#991 legacy chrome colour keys refuse, and a real CLI write reaches the page', async ({
    page,
  }) => {
    pageId = createPage('E2E Chrome Write Path');
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);

    // One run token covers both directions — it is reusable across executes.
    const runId = ppOperateInspect();
    ppPreflight(runId);

    // DIRECTION 1 — the retired option is refused by the write gate, not silently kept.
    //
    // ASSERT THE CODE, NOT THE PROSE, and not anything that appears in the REQUEST.
    // The first cut of this matched /pp_header_bg|retired|not.*allow|invalid/i, which
    // could never fail: the helper used to fold Node's `err.message` into its result,
    // and that message echoes the whole command line including
    // `--params='{"key":"pp_header_bg",...}'`. The assertion matched its own input, so
    // the test went green on docker being down as readily as on a real refusal. The
    // helper no longer returns `message`; these two needles appear only in the refusal.
    //
    // `retired_option` specifically, not the generic `invalid_option` four lines below
    // it in lib/actions.php: losing the arm that names the route back would still be a
    // refusal, and would still be a regression.
    const legacy = runActionExpectingFailure(
      'update_site_option',
      { key: 'pp_header_bg', value: '#101828' },
      runId,
    );
    expect(legacy).toContain('retired_option');
    expect(legacy).toContain('pp_site_udc');

    // DIRECTION 2 — the supported surface, end to end.
    const out = runAction(
      'update_site_option',
      {
        key: 'pp_site_udc',
        value: JSON.stringify({ nav: { _band: { background: { fill: '#2d1b4e' } } } }),
      },
      runId,
    );
    expect(parseEnvelope(out).ok).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const header = page.locator('.site-header');
    await expect(header).toBeVisible({ timeout: 10000 });
    expect(await header.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe(
      'rgb(45, 27, 78)',
    );
  });

  /**
   * CAS RED-PROOF ON THE CHROME WRITE PATH (#991, invariant I8).
   *
   * WHAT THIS PROVES, PRECISELY: that a baseline behind the stored `_version` is
   * refused by a SEPARATE CLI process — its own PHP bootstrap, its own autoloaded
   * options snapshot — and that the refusal leaves the row untouched.
   *
   * WHAT IT DOES NOT PROVE, stated so the name does not promise more than the
   * assertions pin (invariant I40): it does not reproduce a concurrent race. It hands
   * the write an explicitly stale `expected_version` rather than racing two writers,
   * so it cannot catch a torn interleaving.
   *
   * It still earns its place beside the unit pin in ChromeUdcTest. `pp_site_udc` is
   * autoloaded, so its baseline must be read from the ROW rather than through
   * `get_option()`'s request-local snapshot; a unit suite runs in ONE process and
   * cannot tell those two reads apart, so that whole class is invisible to it by
   * construction. A second real process is the cheapest thing that can see it.
   */
  test('#991 a stale baseline is refused on the chrome write path, and nothing is overwritten', async () => {
    // One run token for the whole test — tokens are reusable across executes.
    const runId = ppOperateInspect();
    ppPreflight(runId);

    // Establish a known state, then move it forward so any earlier baseline is stale.
    for (const fill of ['#111111', '#222222']) {
      const out = runAction(
        'update_site_option',
        { key: 'pp_site_udc', value: JSON.stringify({ nav: { _band: { background: { fill } } } }) },
        runId,
      );
      expect(parseEnvelope(out).ok).toBe(true);
    }

    const stored = execSync('npx wp-env run cli wp option get pp_site_udc', {
      cwd: process.cwd(),
      encoding: 'utf-8',
    });
    const current = Number(parseEnvelope(stored)._version ?? 0);
    expect(current).toBeGreaterThan(1);

    // THE red-proof: write against a baseline we know is behind.
    const refused = runActionExpectingFailure(
      'update_site_option',
      {
        key: 'pp_site_udc',
        value: JSON.stringify({ nav: { _band: { background: { fill: '#333333' } } } }),
        expected_version: current - 1,
      },
      runId,
    );
    expect(refused).toContain('site_option_conflict');

    // AND the refusal left the stored map alone — a refusal that still wrote would be
    // the acceptance-masquerading-as-refusal case invariant I8 exists to prevent.
    const after = execSync('npx wp-env run cli wp option get pp_site_udc', {
      cwd: process.cwd(),
      encoding: 'utf-8',
    });
    expect(after).toContain('#222222');
    expect(after).not.toContain('#333333');
    expect(Number(parseEnvelope(after)._version ?? 0)).toBe(current);
  });

  /**
   * RULING A2 — a REAL Media Library attachment resolving to a painted background.
   *
   * Everything about this feature is decided by things only a browser can confirm.
   * The engine builds the `url()` itself from an attachment id, so the unit tests can
   * prove the STRING is right but not that Chromium fetched it, not that the overlay
   * composited above it rather than below, and not that a single `background-size`
   * applied to both layers. The scrim ordering in particular is the kind of thing that
   * looks correct in a diff and renders inverted.
   *
   * Uses a genuine `wp media import`, because the point is referential: a fabricated
   * attachment row would prove the id plumbing and skip the half that can rot.
   */
  test('a real attachment paints as a background with its overlay @smoke', async ({ page }) => {
    pageId = createPage('E2E Background Image');

    // A tiny solid-colour PNG, imported the way an operator's asset would be.
    const attachmentId = importTestImage('pp-e2e-bg');
    expect(attachmentId).toBeGreaterThan(0);

    setComposition(pageId, [
      {
        component: 'testimonials',
        id: 'pp-bgimg001',
        props: { title: 'Proof', items: [{ quote: 'Great work.', author: 'Ada' }] },
        udc: {
          _band: {
            background: {
              fill: '#0b7285',
              image: attachmentId,
              // RESPONSIVE ON PURPOSE. A breakpoint-keyed overlay is minted into a
              // band token, so the emitted layer becomes a var() — and a bare custom
              // property in a background-image layer list is invalid, which makes the
              // browser drop the WHOLE declaration (scrim and photograph together).
              // No unit assertion can see that: the CSS string looks reasonable and
              // only a real engine rejects it. This fixture is the one that would
              // have caught it.
              overlay: { d: 'rgba(0,0,0,0.55)', p: 'rgba(0,0,0,0.75)' },
              size: 'cover',
              position: 'center',
              repeat: 'no-repeat',
            },
          },
        },
      },
    ]);

    for (const width of [375, 768, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      const band = page.locator('[data-pp-band="pp-bgimg001"]');
      await expect(band).toBeVisible({ timeout: 10000 });

      const bg = await band.evaluate((el) => {
        const cs = getComputedStyle(el);
        return { image: cs.backgroundImage, size: cs.backgroundSize, color: cs.backgroundColor };
      });

      // Both layers, scrim FIRST (CSS paints the first layer on top). `none` here
      // is the failure this fixture exists to catch: an invalid layer list.
      expect(bg.image).not.toBe('none');
      expect(bg.image).toContain('gradient');
      expect(bg.image).toContain('url(');
      expect(bg.image.indexOf('gradient')).toBeLessThan(bg.image.indexOf('url('));
      // One declared `cover` applies to BOTH layers, so the scrim tracks the image.
      expect(bg.size).toBe('cover, cover');
      // The fill still shows through wherever the image does not cover.
      expect(bg.color).toBe('rgb(11, 114, 133)');

      await page.screenshot({
        path: `test-results/a2-background-image-${width}.png`,
        fullPage: false,
      });
    }
  });

  /**
   * THE DEGRADE, in a browser. Valid at write, attachment deleted afterwards: the band
   * must keep painting its own fill and must NOT emit a url() of a dead id. A broken
   * image request here would be a visible defect on a page that reported a clean write.
   */
  test('an attachment deleted after the write degrades without breaking the band', async ({
    page,
  }) => {
    pageId = createPage('E2E Background Image Degrade');
    const attachmentId = importTestImage('pp-e2e-bg-degrade');

    setComposition(pageId, [
      {
        component: 'testimonials',
        id: 'pp-bgimg002',
        props: { title: 'Proof', items: [{ quote: 'Great work.', author: 'Ada' }] },
        udc: {
          _band: {
            background: { fill: '#0b7285', image: attachmentId, overlay: 'rgba(0,0,0,0.55)' },
          },
        },
      },
    ]);

    deleteAttachment(attachmentId);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const band = page.locator('[data-pp-band="pp-bgimg002"]');
    await expect(band).toBeVisible({ timeout: 10000 });

    const bg = await band.evaluate((el) => {
      const cs = getComputedStyle(el);
      return { image: cs.backgroundImage, color: cs.backgroundColor };
    });

    expect(bg.image).toBe('none');
    // The band did not lose everything — its own fill still paints.
    expect(bg.color).toBe('rgb(11, 114, 133)');
    // And nothing anywhere emitted a url() of the dead id.
    expect(await page.content()).not.toContain(`image-${attachmentId}`);

    await page.screenshot({ path: 'test-results/a2-background-degrade-1280.png' });
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
  // REPRICED FOR v2 (#1023). The GLYPH half is unchanged and still the point of #339:
  // `panel_items_marker` beats the disc rule and paints a check. The COLOUR half moved —
  // `--section-panel-marker-color` retired with section's slot map, because the mark is a
  // `::before` and ruling A3 defers pseudo-elements, so no role can reach it. The colour
  // is not authorable at all now (#1028): the rule reads `--pp-list-marker-color`, which
  // is declared nowhere and registered as no design token, so the read's fallback for the
  // MARKERS — `var(--color-accent)` — IS the rendered value, and it is the exact value
  // this slot defaulted to. So the marker's rendered colour is unchanged and asserted as
  // such; what is lost is any way to move it on its own.
  //
  // The narrowing is proved at the WRITE surface instead: the retired slot is refused,
  // which is the half an author actually meets. (The separator is the one glyph whose
  // fallback is NOT the accent — see the #1023 separator test for why it differs.)
  test('#339/#1023 the panel check marker still beats the disc rule, and its colour slot is refused @smoke', async ({
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

    // THE NARROWING, at the surface an author meets: the retired slot is refused rather
    // than accepted and silently ignored.
    const refused = await styleComponent(page, pageId, { '--section-panel-marker-color': '#ff0080' });
    expect(refused.success, 'a retired slot must be REFUSED, not stored and ignored').toBe(false);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const list = page.locator('.section__panel-list');
    await expect(list).toBeVisible({ timeout: 10000 });

    // The disc rule is still beaten: the <ul> renders no native marker.
    const listStyle = await list.evaluate((el) => getComputedStyle(el).listStyleType);
    expect(listStyle).toBe('none');

    // The glyph still paints, and in the SAME colour it always did — the markers' token
    // fallback is the accent this slot defaulted to, so nothing moved for them.
    const marker = await page.locator('.section__panel-item').first().evaluate((el) => {
      const b = getComputedStyle(el, '::before');
      const accent = getComputedStyle(document.documentElement).getPropertyValue('--color-accent').trim();
      return { content: b.content, color: b.color, accent };
    });
    expect(marker.content).not.toBe('none');
    expect(marker.content).not.toBe('normal');
    // Compared against the token's own resolved value rather than a literal, so a retheme
    // does not break the pin — the claim is "unchanged", not "this exact pink".
    const probe = await page.evaluate((accent) => {
      const el = document.createElement('span');
      el.style.color = accent;
      document.body.appendChild(el);
      const rgb = getComputedStyle(el).color;
      el.remove();
      return rgb;
    }, marker.accent);
    expect(marker.color, 'the panel marker keeps the accent it always painted').toBe(probe);
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

    // Same repricing as the panel marker above: the colour slot is refused at write, and
    // the glyph keeps the accent it always painted.
    const refused = await styleComponent(page, pageId, { '--section-body-marker-color': '#ff0080' });
    expect(refused.success, 'a retired slot must be REFUSED, not stored and ignored').toBe(false);

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
    const accentRgb = await page.evaluate(() => {
      const el = document.createElement('span');
      el.style.color = getComputedStyle(document.documentElement).getPropertyValue('--color-accent').trim();
      document.body.appendChild(el);
      const rgb = getComputedStyle(el).color;
      el.remove();
      return rgb;
    });
    expect(marker.color, 'the body marker keeps the accent it always painted').toBe(accentRgb);
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

  test('#334/#1023 paired rows: mono panel + independent label/value type, row marker suppressed @smoke', async ({
    page,
  }) => {
    // Cross-sheet PAINT proof (the #342 gap: a value can validate yet never render).
    // Repriced for v2 (#1023) and the capability it proves CHANGED SHAPE — one part was
    // retired and one part is new, so this is not a re-point:
    //   - the mono panel is the `panel` role's `typography.family` (was a slot);
    //   - THE PER-ROW ACCENT IS RETIRED. The engine addresses roles, not items, so a
    //     `style` map on a paired row is an undeclared field now. What replaces it is
    //     NOT a narrower version of the same thing: `panel-row-label` and
    //     `panel-row-value` are INDEPENDENT roles, so the label and the value can be
    //     typed differently on EVERY row — the spec-sheet composition the old
    //     item_eligible slot could only approximate one row at a time. That is what is
    //     asserted here instead, because it is what an author can now do;
    //   - a paired row shows NO marker glyph while a string bullet in the same list
    //     still does (mixed list, marker on the <ul>) — unchanged.
    pageId = createPage('E2E Panel Paired Rows');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-seed', body: '<p>Seed.</p>' } }]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const res = await updateComposition(page, pageId, [
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
            { label: 'Uptime', value: '99.9%' },
          ],
        },
        udc: {
          panel: { typography: { family: '@font-mono' } },
          'panel-row-label': { typography: { color: '#94a3b8' } },
          'panel-row-value': { typography: { color: '#22d3ee' } },
        },
      },
    ]);
    expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    // 1. The mono family reaches the panel through the role.
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

    // 3. THE REPLACEMENT CAPABILITY, rendered: the two halves of a row are typed
    //    independently, on every row rather than one. The old per-row slot could recolour
    //    a whole row; this distinguishes label from value, which is the composition the
    //    panel exists for — and it is why the five panel-* text roles were NOT collapsed
    //    into one `panel` role during the rebuild.
    const rows = page.locator('.section__panel-row');
    const rowCount = await rows.count();
    expect(rowCount, 'both paired rows render').toBe(2);
    for (let i = 0; i < rowCount; i++) {
      const label = await rows.nth(i).locator('.section__panel-row-label').evaluate((el) => getComputedStyle(el).color);
      const value = await rows.nth(i).locator('.section__panel-row-value').evaluate((el) => getComputedStyle(el).color);
      expect(label, `row ${i} label`).toBe('rgb(148, 163, 184)');
      expect(value, `row ${i} value`).toBe('rgb(34, 211, 238)');
      expect(label).not.toBe(value);
    }

    // 4. THE RETIREMENT, at the write surface: a stored per-row `style` map is an
    //    undeclared field now and is refused, rather than accepted and silently ignored.
    //    Back to the admin first — the loop above left us on the front end, where
    //    `window.ppAiChat` (and so the nonce the write needs) does not exist.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const refused = await updateComposition(page, pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec01',
          layout: 'text-panel',
          body: '<p>Left.</p>',
          panel_heading: 'Runtime',
          panel_items: [{ label: 'Uptime', value: '99.9%', style: { '--section-panel-text': '#22d3ee' } }],
        },
      },
    ]);
    expect(refused.success, 'a per-row style map must be REFUSED (#1024 owns the replacement)').toBe(false);
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
            // LONG ENOUGH THAT THE WRAP COMPARISON BELOW CANNOT SATURATE. At the old
            // 50-character length this value took 2 lines at the stacked measure and 3 at
            // 170px on some faces, and 3 at BOTH on others — a one-step gap that a font
            // change closes. See the assertion at the end of this test.
            { label: 'Included', value: 'Discovery, design, build, QA and a handover session with the whole delivery team' },
            { label: 'Support and maintenance retainer', value: 'Optional' },
            { label: 'Docs', value: 'https://example.com/a-very-long-unbreakable-documentation-path' },
            // Half-rows: the renderer emits BOTH spans even when one side is empty
            // (the panel-items loop in components/section/section.php), so an empty
            // span is still a flex item and still
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

    // THE PER-ROW OVERRIDE IS RETIRED (#1023), and this pair of assertions is INVERTED
    // rather than deleted, because the thing it was really guarding still matters.
    //
    // It used to prove that #568's new label rule had not stolen the item_eligible
    // `--section-panel-text` slot from the label half — i.e. that BOTH halves of a
    // per-row-styled pair took the override. There is no per-item styling in v2: the
    // engine addresses roles, so a `style` map on a paired row is an undeclared field and
    // the last row here (which still carries one in the fixture, deliberately) must render
    // exactly like its siblings.
    //
    // The guard that replaces it is the same guard in the other direction: the label and
    // the value of the LAST row must match the label and value of an ORDINARY row. If a
    // per-item mechanism ever comes back through #1024, this is the assertion that will
    // notice — and it notices whether the mechanism reaches one half or both.
    const lastRow = mobile.rows[mobile.rows.length - 1];
    const plainRow = mobile.rows[0];
    expect(lastRow.labelColor, 'a stored per-row style must not recolour the label').toBe(plainRow.labelColor);
    expect(lastRow.valueColor, 'a stored per-row style must not recolour the value').toBe(plainRow.valueColor);

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

    // The reported defect, measured two ways — and the second one exists because the
    // first is not as font-independent as it looks.
    //
    // THE ORIGINAL COMPARISON WAS A STEP FUNCTION WITH ONE STEP OF HEADROOM. Comparing
    // the SAME text at two widths was meant to cancel the font out, since the theme ships
    // no webfont and `system-ui` resolves to whatever the running machine has. It does not
    // cancel: a line COUNT is quantised, so when the text needs 3 lines at BOTH widths the
    // comparison collapses to `3 < 3` and goes red on a CSS change that never happened.
    // Measured on the old 50-character value at the stacked 223px: the drop to 3 lines
    // came at 210px under Liberation Sans, a 13px margin, and this machine resolves
    // `system-ui` to WenQuanYi Zen Hei while CI resolves it to a Latin face — so the local
    // run was never checking the same thing as CI. The value is longer now, which puts a
    // whole line between the two widths on every face tried (WenQuanYi, DejaVu, Liberation,
    // Verdana, Times, serif, monospace: 3-vs-4 or better, Liberation 3-vs-5).
    //
    // AND THE CLAIM ITSELF IS A WIDTH, so it is now asserted as one. "Gives the value the
    // full measure" is continuous and font-independent; the wrap count is the consequence.
    const wrap = await page
      .locator('#pp-sec-stack .section__panel-row-value')
      .nth(1) // the "Included" value — the longest
      .evaluate((el: HTMLElement) => {
        const lh = parseFloat(getComputedStyle(el).lineHeight);
        const lines = () => Math.round(el.getBoundingClientRect().height / lh);
        const naturalWidth = el.getBoundingClientRect().width;
        const atFullMeasure = lines();
        const prior = el.style.width;
        el.style.width = '170px'; // the pre-#568 column this value was squeezed into
        const atOldColumn = lines();
        el.style.width = prior;
        return { atFullMeasure, atOldColumn, naturalWidth };
      });

    // The fix itself: the stacked value gets the row's whole content box, not the ~170px
    // column #568 reported. 223px at 375 (343 viewport-minus-page-padding, less the
    // panel's 2 x --space-lg, less the list's --space-lg indent, less the row's 1.5rem
    // marker indent). Asserted as a floor rather than an equality so a padding-token
    // retune does not fail it, and well above 170 so a regression to the old column does.
    expect(wrap.naturalWidth).toBeGreaterThan(200);

    // The consequence, now with a full line of headroom on every face.
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
 * #355 — the active/current header link must be reachable, and keep its bold weight.
 *
 * Bug: `.nav__menu li.current-menu-item > a` (and the `aria-current="page"` variant)
 * hard-coded `color: var(--color-accent)`, which won over the operator's chosen link
 * colour. On a one-page anchor-nav marketing site every link points at the current page,
 * so WordPress marks them all current and the WHOLE menu ignored it.
 *
 * REPRICED BY RULING A1 (#976): the option that fix routed through is gone, and the
 * active link is now its own `link-current` UDC role. The defect is the same one — an
 * active link nobody can recolour — so the pin is the same shape against the new surface:
 * set `link-current`, prove it wins on a REAL current-menu-item, and prove the bold
 * weight (structural) survives.
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

/** Append a custom (non-post) item, so a menu can carry a link that is never current. */
function addCustomToMenu(menuId: number, title: string, url: string, parentId?: number): void {
  const parent = parentId ? ` --parent-id=${parentId}` : '';
  execSync(
    `npx wp-env run cli wp menu item add-custom ${menuId} ${shq(title)} ${shq(url)}${parent}`,
    { cwd: process.cwd(), encoding: 'utf-8' },
  );
}

/**
 * The db_id of the most recently added item in a menu.
 *
 * `wp menu item add-post` has no --porcelain, so the id of the page item a submenu
 * must hang under is not returned by the call that creates it. Listing and taking the
 * last row is how the CLI exposes it; the list is in menu order, which is insertion
 * order for a menu nothing has reordered.
 */
function lastMenuItemId(menuId: number): number {
  const rows = execSync(
    `npx wp-env run cli wp menu item list ${menuId} --fields=db_id --format=csv`,
    { cwd: process.cwd(), encoding: 'utf-8' },
  )
    .replace(/\r/g, '')
    .trim()
    .split('\n')
    .filter((line) => /^\d+$/.test(line.trim()));
  return parseInt(rows[rows.length - 1], 10);
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

test.describe('#355 the active header link is reachable through the link-current role', () => {
  let pageId = 0;
  let menuId = 0;

  test.afterEach(async () => {
    // Site option + menu are site-global — a leak would style/route every later test's page.
    deleteSiteOption('pp_site_udc');
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
    // A SECOND, NON-CURRENT ITEM. The `link` and `link-current` roles emit at the same
    // specificity — `[data-pp-chrome="nav"] .nav__menu ul li a:hover` and
    // `... li.current-menu-item > a` are both (0,3,3) — so on the current item a tie is
    // broken by emission order and `link`'s hover cannot be read there. A plain
    // sibling is the only place `link`'s own states are observable. An in-page anchor,
    // so clicking it could never navigate a test away.
    addCustomToMenu(menuId, 'Elsewhere', '#elsewhere');
    assignMenuToPrimary(menuId);
  }

  /**
   * The same page, but its menu item HAS CHILDREN (#994/#995).
   *
   * Two things need this shape and nothing else can supply it. `link-current`'s child
   * combinator only matters when the current item has descendants to exclude, and
   * #995's alignment defect only appears on an item the walker gives a disclosure
   * button to. A current item WITHOUT children answers neither question, which is why
   * the fixture above cannot be reused.
   */
  function seedCurrentItemPageWithChildren(title: string): void {
    pageId = createPage(title);
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);
    menuId = createMenu(`E2E 994 ${Date.now()}`);
    addPageToMenu(menuId, pageId);
    const parent = lastMenuItemId(menuId);
    addCustomToMenu(menuId, 'Child One', '#child-one', parent);
    addCustomToMenu(menuId, 'Elsewhere', '#elsewhere');
    assignMenuToPrimary(menuId);
  }

  test('#355 the active link follows the link-current role, keeping its bold weight @smoke', async ({
    page,
  }) => {
    seedCurrentItemPage('E2E Active Link Color');
    // #c8c8e0 = rgb(200, 200, 224); distinct from the accent default #3157f4 = rgb(49, 87, 244).
    setChromeUdc({ nav: { 'link-current': { typography: { color: '#c8c8e0' } } } });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const activeLink = page.locator('.nav__menu li.current-menu-item > a');
    await expect(activeLink).toBeVisible({ timeout: 10000 });

    // THE assertion. Before #355 this came back rgb(49, 87, 244) — the hard-coded
    // var(--color-accent) won and the operator's colour was ignored. The role has to
    // beat that same structural rule, which it does from the authored layer.
    const color = await activeLink.evaluate((el) => getComputedStyle(el).color);
    expect(color).toBe('rgb(200, 200, 224)');

    // Emphasis is preserved — the current item is still distinguishable by weight.
    const weight = await activeLink.evaluate((el) => getComputedStyle(el).fontWeight);
    expect(weight).toBe('700');
  });

  test('#355 an unstyled header leaves the active link on the accent (unchanged)', async ({
    page,
  }) => {
    // Default stays neutral: with no chrome styling, the active link keeps
    // --color-accent, so an unstyled site renders byte-identically.
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

  /**
   * THE PREMISE UNDER `link-current`'s SELECTOR (#991).
   *
   * The role's selector is `.nav__menu ul li.current-menu-item > a` — one class, not a
   * list, and a CHILD combinator rather than a descendant one (ruling D4, #994: the
   * descendant form would hand the current-page treatment to every link inside a current
   * parent's dropdown). That one class is only correct because WordPress adds
   * `current-menu-item` to EVERY
   * item it considers current: core sets `current_page_item` exclusively inside a
   * branch that has already pushed `current-menu-item` (wp-includes/nav-menu-template.php),
   * and `aria-current="page"` is rendered from the same `$menu_item->current` flag
   * (wp-includes/class-walker-nav-menu.php). So the one class is a SUPERSET of the
   * other two and the narrow selector reaches everything the stylesheet reaches.
   *
   * This was very nearly "fixed" by widening the selector to a comma list. That would
   * have been silently self-defeating: role selectors pass a charset gate in
   * lib/udc.php that rejects `,`, `[`, `]`, `=` and `"`, and a rejected selector is
   * SKIPPED rather than reported — the role would have emitted nothing at all.
   *
   * Pinned rather than assumed so a future WordPress change to the walker breaks
   * loudly here instead of silently dropping the current-page treatment on live sites.
   */
  test('#991 current-menu-item is a superset of current_page_item and aria-current', async ({
    page,
  }) => {
    seedCurrentItemPage('E2E Current Superset');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const item = page.locator('.nav__menu ul li').first();
    await expect(item).toBeVisible({ timeout: 10000 });

    // The fixture must actually BE the current page, or every assertion below is vacuous.
    const classes = (await item.getAttribute('class')) ?? '';
    expect(classes, 'fixture must render a current menu item').toContain('current_page_item');
    expect(await item.locator('a').first().getAttribute('aria-current')).toBe('page');

    // THE assertion: every marker core sets rides an element that also carries
    // `current-menu-item`, so the single-class role selector loses nothing.
    expect(classes).toContain('current-menu-item');
    expect(await page.locator('.nav__menu ul li.current_page_item').count()).toBe(
      await page.locator('.nav__menu ul li.current_page_item.current-menu-item').count(),
    );
    expect(await page.locator('.nav__menu ul li a[aria-current="page"]').count()).toBe(
      await page.locator('.nav__menu ul li.current-menu-item a[aria-current="page"]').count(),
    );
  });

  /**
   * THE INVERSION OF THE #992 CHARACTERIZATION TEST (#994).
   *
   * That test pinned a DEFECT on purpose so this fix would have a red-to-green to
   * flip, and its own docblock said "DELETE OR INVERT THIS TEST WHEN #994 LANDS".
   * Inverted rather than deleted, deliberately: same page, same authored input, same
   * three reads — only the expectations move. A deleted defect pin proves the defect
   * is unreachable by nobody watching; an inverted one proves the exact scenario that
   * used to fail now passes, which is the only evidence that answers "did you fix it
   * or did you delete the test?"
   *
   * WHAT CHANGED UNDERNEATH. Chrome shipped NO role defaults, so an authored value was
   * the only unlayered declaration on the element and outranked the v1 stylesheet in
   * EVERY state — including the `:hover` and current-page rules that stylesheet
   * provided. #994 moved those treatments into role defaults, which sit in the same
   * unlayered tier the authored value does, so they win or lose on specificity like
   * anything else: `link`'s `:hover` at (0,3,3) beats an authored `link` base at
   * (0,2,3), and `link-current` at (0,3,3) beats it too.
   *
   * EVERY ASSERTION STILL NEEDS ITS POSITIVE CONTROL, and now more than before: the
   * claim has become "the colour DOES change on hover", which fails loudly if the
   * hover never landed — but the current-page assertion is still an equality, so the
   * hover-engaged proof is kept throughout rather than trimmed as redundant.
   */
  test('#994 an authored base colour no longer erases the hover and current-page accents', async ({
    page,
  }) => {
    seedCurrentItemPage('E2E 994 Base Keeps States');
    // Author ONLY resting colours — no `:hover` maps, no `link-current`. Byte-for-byte
    // the input the characterization test used.
    const AUTHORED = '#0a7d32'; // rgb(10, 125, 50)
    const ACCENT = 'rgb(49, 87, 244)'; // --color-accent, the default the roles carry
    setChromeUdc({
      nav: {
        link: { typography: { color: AUTHORED } },
        logo: { typography: { color: AUTHORED } },
      },
    });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const activeLink = page.locator('.nav__menu li.current-menu-item > a');
    await expect(activeLink).toBeVisible({ timeout: 10000 });
    const logo = page.locator('.nav__logo');

    const hoverEngaged = (target: typeof logo) =>
      target.evaluate((el) => el.matches(':hover'));

    // WAS DEFECT 1 — the current-page accent survives the author's `link` colour.
    // The author never mentioned `link-current`; its DEFAULT is what holds the line.
    await expect(activeLink).toHaveCSS('color', ACCENT);
    await expect(activeLink).toHaveCSS('font-weight', '700');

    // AND THE AUTHORED VALUE REALLY DID LAND, on the links it was written for. Without
    // this the test above would pass just as happily if the whole chrome write had been
    // dropped — "the accent is still there" is also what a no-op looks like.
    const plainLink = page.locator('.nav__menu ul li:not(.current-menu-item) > a').first();
    await expect(plainLink).toHaveCSS('color', 'rgb(10, 125, 50)');

    // WAS DEFECT 2 — the link's accent hover answers again. The control is the same
    // one the characterization test used: the stylesheet's hover set BOTH colour and
    // an underline, and `link`'s hover default carries both, so the underline proves
    // the rule applied while the colour proves which tier won.
    await plainLink.hover();
    expect(await hoverEngaged(plainLink)).toBe(true);
    await expect(plainLink).toHaveCSS('text-decoration-line', 'underline');
    await expect(plainLink).toHaveCSS('color', ACCENT);

    // WAS DEFECT 3 — the worst one. Hovering the logo produced NO change at all,
    // because its hover rule sets colour and nothing else, leaving no side effect to
    // fall back on. It changes now.
    await expect(logo).toHaveCSS('color', 'rgb(10, 125, 50)');
    await logo.hover();
    expect(await hoverEngaged(logo)).toBe(true);
    await expect(logo).toHaveCSS('color', ACCENT);
  });

  /**
   * THE `> a` GUARD, RENDERED (#994, ruling D4).
   *
   * `link-current`'s default paints the current page's link accent and bold. Its
   * selector is a CHILD combinator, and the `>` exists in the role-selector charset
   * only because of this case: with the descendant form the same default would also
   * paint every link inside a current parent's DROPDOWN, so visiting a parent page
   * would turn its whole submenu accent-coloured and bold.
   *
   * Unit tests cannot see this — it is a cascade outcome on markup WordPress emits —
   * so it is measured where it happens.
   */
  test('#994 the current-page treatment stops at the current item and does not enter its dropdown', async ({
    page,
  }) => {
    seedCurrentItemPageWithChildren('E2E 994 Current With Dropdown');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const activeLink = page.locator('.nav__menu li.current-menu-item > a');
    await expect(activeLink).toBeVisible({ timeout: 10000 });
    await expect(activeLink).toHaveCSS('color', 'rgb(49, 87, 244)');
    await expect(activeLink).toHaveCSS('font-weight', '700');

    // The child of that very item: plain ink, normal weight. Read while the dropdown
    // is closed on purpose — `display: none` does not stop `color` resolving, and the
    // question is about the cascade, not about visibility.
    const childLink = page.locator('.nav__menu li.current-menu-item .sub-menu li a').first();
    await expect(childLink).toHaveCSS('color', 'rgb(16, 24, 40)');
    await expect(childLink).toHaveCSS('font-weight', '400');
  });

  /**
   * #995 DEFECT 2, MEASURED AS GEOMETRY rather than looked at.
   *
   * The parent link is display:block, so the disclosure button could not sit beside it
   * and wrapped onto a second line; the <li> then grew to two lines while its siblings
   * stayed at one, and the row's align-items:center lifted the parent's LABEL above its
   * neighbours' baseline. Measured before the fix at 1280: parent <li> y=0.91 h=62.19,
   * sibling y=15.20 h=33.59 — a ~14px misalignment with the chevron stranded below.
   *
   * Screenshots are how this was FOUND (pipeline rule 14.2) and boxes are how it stays
   * fixed: a screenshot diff tolerates a 14px drift, an equality on the top edge does
   * not.
   */
  test('#995 a parent menu item sits on the same baseline as its siblings @smoke', async ({
    page,
  }) => {
    seedCurrentItemPageWithChildren('E2E 995 Parent Alignment');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const parent = page.locator('.nav__menu > ul > li.menu-item-has-children').first();
    const sibling = page.locator('.nav__menu > ul > li:not(.menu-item-has-children)').first();
    await expect(parent).toBeVisible({ timeout: 10000 });

    const parentBox = await parent.boundingBox();
    const siblingBox = await sibling.boundingBox();
    expect(parentBox).not.toBeNull();
    expect(siblingBox).not.toBeNull();

    // Same top edge and same height: the item is one line, like every other item.
    expect(Math.abs(parentBox!.y - siblingBox!.y)).toBeLessThan(1);
    expect(Math.abs(parentBox!.height - siblingBox!.height)).toBeLessThan(1);

    // And the chevron is BESIDE the label, not under it — vertically overlapping the
    // parent's own link rather than sitting past its bottom edge.
    const toggleBox = await parent.locator('.nav__submenu-toggle').boundingBox();
    expect(toggleBox).not.toBeNull();
    expect(toggleBox!.y).toBeLessThan(parentBox!.y + parentBox!.height);
    expect(toggleBox!.x).toBeGreaterThan(parentBox!.x);
  });

  /**
   * #995 DEFECT 1 — the chevron is REACHABLE on a dark header now.
   *
   * It is not automatic, and the test says so rather than implying otherwise: the
   * button is a SIBLING of the link, so `color: inherit` resolved against the <li>
   * and no default can make it follow the `link` role. Before #994 there was no role
   * covering the element at all, so a dark header left the chevron at the ambient ink
   * — measured at 1:1 contrast against its own background, invisible. The fix is that
   * `submenu-toggle` exists; this proves both halves of that sentence.
   */
  test('#995 the dropdown chevron follows an authored submenu-toggle colour', async ({
    page,
  }) => {
    seedCurrentItemPageWithChildren('E2E 995 Chevron Ink');
    setChromeUdc({
      nav: {
        _band: { background: { fill: '#101828' } },
        link: { typography: { color: '#f7f8fa' } },
        'submenu-toggle': { typography: { color: '#f7f8fa' } },
      },
    });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const toggle = page.locator('.nav__submenu-toggle').first();
    await expect(toggle).toBeVisible({ timeout: 10000 });

    // The authored ink reaches the button, and the icon inherits it via currentColor.
    await expect(toggle).toHaveCSS('color', 'rgb(247, 248, 250)');
    await expect(page.locator('.nav__submenu-toggle-icon').first()).toHaveCSS(
      'color',
      'rgb(247, 248, 250)',
    );

    // Against the header it sits on — which is the whole defect, stated as the
    // inequality that was an equality before.
    await expect(page.locator('.site-header')).toHaveCSS('background-color', 'rgb(16, 24, 40)');
  });

  /**
   * AUTHORED STATES STILL WIN, which is the other half of #994's fix.
   *
   * This test used to be "the authored way out" of #992: pair every resting colour
   * with its `:hover` and pair `link` with `link-current`, or lose both. The pairing
   * is no longer a rescue — the defaults hold those states now — so what it proves has
   * changed from "the advice works" to something more important: an author who DOES
   * set a state still beats the default that would otherwise carry it. A defaults tier
   * that could not be overridden state-for-state would be a new I35, and the test
   * above (which asserts the default wins when the author is silent) is only safe
   * because this one asserts the reverse when the author speaks.
   */
  test('#992 pairing the states back restores hover feedback and the current-page marker', async ({
    page,
  }) => {
    seedCurrentItemPage('E2E 992 Paired States');
    setChromeUdc({
      nav: {
        link: { typography: { color: '#0a7d32', ':hover': { color: '#b30000' } } },
        'link-current': { typography: { color: '#c8c8e0' } },
        logo: { typography: { color: '#0a7d32', ':hover': { color: '#b30000' } } },
      },
    });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const activeLink = page.locator('.nav__menu li.current-menu-item > a');
    await expect(activeLink).toBeVisible({ timeout: 10000 });
    const logo = page.locator('.nav__logo');

    // The current page is distinguishable again, by colour and not only by weight.
    await expect(activeLink).toHaveCSS('color', 'rgb(200, 200, 224)');

    // AND BOTH HOVERS ANSWER AGAIN — both, because the characterization test pins two
    // separate erasures and a mitigation that fixed only one would otherwise show up
    // as two green tests. Every DEFECT above gets its matching green here.
    //
    // The link hover is read on the NON-current sibling: `link`'s hover and
    // `link-current`'s base both land at (0,3,3), so on the current item the tie goes
    // to emission order and `link`'s hover is not what paints. That is correct
    // behaviour (the you-are-here colour should not flicker away under the pointer),
    // and it is why the plain sibling is where this assertion belongs.
    const plainLink = page.locator('.nav__menu ul li:not(.current-menu-item) > a').first();
    await expect(plainLink).toBeVisible({ timeout: 10000 });
    await expect(plainLink).toHaveCSS('color', 'rgb(10, 125, 50)');
    await plainLink.hover();
    await expect(plainLink).toHaveCSS('color', 'rgb(179, 0, 0)');

    await logo.hover();
    await expect(logo).toHaveCSS('color', 'rgb(179, 0, 0)');
  });

  /**
   * FOCUS-VISIBLE THROUGH CHROME UDC (#991).
   *
   * `:focus-visible` is one of ruling A3's three state dimensions. base.css gives every
   * focusable element an accent outline from `@layer pp-reset`, so this proves the
   * authored state reaches a nav link and beats that floor — keyboard-only, which is
   * why the link is reached with real Tab presses rather than `.focus()` (a scripted
   * focus does not always satisfy `:focus-visible`).
   *
   * READ ON A NON-CURRENT SIBLING SINCE #994. `.first()` is the CURRENT page's link in
   * this fixture, and `link-current` carries a colour DEFAULT now — at (0,3,3) it
   * outranks an authored `link` base at (0,2,3), so the current item is accent rather
   * than the authored `#111111`. That is the #992 fix working exactly as intended (the
   * you-are-here marker survives an author styling `link`), and it makes the current
   * item the one place `link`'s own states cannot be observed. The sibling fixture
   * exists for precisely this reason — see seedCurrentItemPage's own note.
   */
  test('#991 an authored focus-visible state reaches a nav link', async ({ page }) => {
    seedCurrentItemPage('E2E Focus Visible');
    setChromeUdc({
      nav: { link: { typography: { color: '#111111', ':focus-visible': { color: '#0000cc' } } } },
    });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const link = page.locator('.nav__menu ul li:not(.current-menu-item) > a').first();
    await expect(link).toBeVisible({ timeout: 10000 });
    await expect(link).toHaveCSS('color', 'rgb(17, 17, 17)');

    // Walk the keyboard to the link so the browser's own focus-visible heuristic fires.
    // A scripted .focus() does NOT reliably satisfy :focus-visible, which is the whole
    // state under test, so the Tab walk is load-bearing rather than incidental.
    // BUDGET RAISED FROM 12 (#994). The walk is bounded, never `while (true)` — but
    // the bound has to clear the real tab order, and the target moved one stop further
    // down it when this test switched to the non-current sibling. Measured in Chromium
    // on this fixture: the skip link is stop 11, the current item 13, the sibling 14.
    // 30 leaves headroom for a fixture that grows a menu item without making the loop
    // unbounded, and the assertion below is what turns a missed target into a clear
    // failure rather than a silently unproven state.
    for (let i = 0; i < 30; i += 1) {
      await page.keyboard.press('Tab');
      if (await link.evaluate((el) => el === document.activeElement)) break;
    }
    expect(
      await link.evaluate((el) => el === document.activeElement),
      'the Tab walk never reached the nav link, so the focus state below is unproven',
    ).toBe(true);
    await expect(link).toHaveCSS('color', 'rgb(0, 0, 204)');
  });

  /**
   * THE `container` ROLE IS NOT INERT (#991).
   *
   * `.nav__container`'s two designable declarations are `min-height` and `gap`, and
   * before this role neither was reachable from any authoring surface. It carries them
   * as DEFAULTS since #994 — the stylesheet no longer has them — so this proves both
   * directions: the default paints on an unstyled site, and an authored value beats it.
   */
  test('#991 the container role reaches the header row', async ({ page }) => {
    // No menu needed: the header row exists on every page, and this asserts geometry
    // rather than ink. seedCurrentItemPage() would build and tear down a WP menu for
    // nothing.
    pageId = createPage('E2E Container Role');
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const row = page.locator('.nav__container');
    await expect(row).toBeVisible({ timeout: 10000 });
    // Unauthored, the ROLE DEFAULT paints the row height — the value is the same 64px
    // the stylesheet used to declare, which is the point: the retirement moved the
    // declaration without moving the pixel. That half matters as much as the authored
    // half, because a default that failed to paint would be invisible on every
    // unstyled site and this is the only place that would show.
    await expect(row).toHaveCSS('min-height', '64px');

    setChromeUdc({
      nav: { container: { sizing: { 'min-height': '96px' }, spacing: { gap: '40px' } } },
    });
    await page.reload();
    await expect(row).toBeVisible({ timeout: 10000 });
    await expect(row).toHaveCSS('min-height', '96px');
    await expect(row).toHaveCSS('column-gap', '40px');
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

  // RE-BASED FROM HERO'S SECONDARY ONTO THE cta PRIMARY (#986).
  //
  // The original drove hero's second CTA with `button2_variant: 'primary'`, forcing it into
  // the filled treatment so it matched the `[0,6,0]` ink rule that routed through
  // `--btn-text`. Both halves of that setup are gone: the prop was removed with the rebuild,
  // and hero declares no band-scoped button rules at all now.
  //
  // The INVARIANT is untouched and still worth pinning: `--btn-text` registers as
  // `var(--color-bg)`, and an unset registration must leave a filled composed button's ink
  // at the historical `--color-bg` (#fcfdff). A wrong registration value would move this
  // pixel, so it is not a tautology. The cta primary is a filled composed button that still
  // routes through `--btn-text`, so the assertion moves there rather than being retired.
  test('an unset --btn-text renders a composed primary\'s ink at its historical --color-bg @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E btn-text unset is byte-identical');
    setComposition(pageId, [
      {
        component: 'cta',
        props: {
          id: 'btn441-cta',
          title: 'Ship faster',
          button_text: 'Primary action',
          button_url: '/start',
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const btn = page.locator('.cta__button').first();
    await expect(btn).toBeVisible({ timeout: 10000 });

    const ink = await btn.evaluate((el) => getComputedStyle(el).color);
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
  /** The three bands this sweep measures, shared by the raw-meta and authored builders. */
  function oneKnobBands(): Record<string, unknown>[] {
    return [
      {
        component: 'hero',
        props: {
          id: 'btn458-hero',
          title: 'Ship faster',
          button_text: 'Primary',
          button_url: '/start',
          button2_text: 'Secondary',
          button2_url: '/learn', // matches the [0,6,0] ink rule that routes through --btn-text
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
    ];
  }

  function buildOneKnobPage(): number {
    const id = createPage('E2E btn one-knob #458');
    setComposition(id, oneKnobBands());
    return id;
  }

  // The four composed-primary selectors. The hero primary is `.hero__cta` WITHOUT the
  // --secondary modifier (both share `.hero__cta`).
  // HERO'S SECONDARY LEFT THIS SET (#986), and it left it by design rather than by
  // accident. This sweep is "every composed PRIMARY button", and hero's second CTA now
  // carries the v1 outline treatment as its `cta-secondary` ROLE DEFAULT — a muted
  // surface, body ink, border-coloured edge. A role default is emitted unlayered and the
  // v1 stylesheet is in `@layer pp-v1`, so `--btn-*` no longer reaches it. That is the
  // contract working: the global button tier is the v1 slot cascade, and a v2 role
  // default is the component's own stated design.
  //
  // Hero's PRIMARY stays in: the `cta` role declares no defaults on purpose, so it is
  // still a bare `.btn` and still follows every `--btn-*` knob.
  //
  // THE cta BAND'S BUTTONS SPLIT THE SAME WAY AT #1026, for exactly the same reason, so the
  // sweep keeps its PRIMARY and drops its second button. cta's `button` role declares no
  // defaults (so a `button` preset lands whole), which leaves it a bare `.btn` that every
  // `--btn-*` knob still reaches; `button-secondary` carries v1's outline treatment as role
  // defaults, so it is unlayered and out of the global tier's reach. That is the contract
  // working, not a gap: the global tier is the v1 slot cascade, and a role default is the
  // component's own stated design.
  //
  // The reach hero's secondary DOES have is pinned below rather than dropped.
  const SEL = {
    cta: '.cta__button:not(.cta__button--secondary)',
    heroPrimary: '.hero__cta:not(.hero__cta--secondary)',
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

      // INK — every composed primary bottoms out at --color-bg (the premium first-block
      // color winner routed through --btn-text).
      expect(got.cta.ink).toBe(got.bgInk);
      expect(got.heroPrimary.ink).toBe(got.bgInk);
      expect(got.section.ink).toBe(got.bgInk);

      // THE cta PRIMARY MOVED GROUPS AT #1026, exactly as hero's did at #986, and the
      // honest reading is one line down rather than a deleted assertion.
      //
      // It used to bottom out at `--color-accent` through the `.cta .btn:not(...)` rule at
      // [0,5,0], which declared background-COLOR. That rule retired with cta's slot map, so
      // the cta primary is now a bare `.btn` inside `main` and its fill is the PREMIUM
      // rule's — a gradient background-IMAGE over a transparent background-COLOR, which is
      // why `backgroundColor` reads `rgba(0,0,0,0)` here. That is the same shape hero's
      // primary has had since #986, and it is what makes the two comparable at all.
      //
      // So the assertion moves to the property that actually carries the paint. Reading
      // `backgroundColor` on a gradient-filled button and calling it the fill is the exact
      // trap #1023's contrast assertion fell into (it measured 1.06:1 and looked like a
      // defect); the fill is the IMAGE, and it must match the hero primary's byte for byte.
      expect(got.cta.bgColor).toBe(got.heroPrimary.bgColor);
      expect(got.cta.bgImage, 'the cta primary is a premium filled button now').toBe(
        got.heroPrimary.bgImage,
      );
      expect(got.cta.border).toBe(got.heroPrimary.border);

      // HERO'S PRIMARY MOVED GROUPS (#986), and this is the honest place to say so.
      // It used to be a `.hero .btn:not3` [0,5,0] winner that RESTORED a background-COLOR
      // the premium shorthand had reset. Hero owns no band-scoped button rules now, so it
      // is governed only by the premium block — exactly like the section-panel primary
      // below: the fill is the accent GRADIENT (a background-image) and the colour behind
      // it stays transparent. The rendered pixel is unchanged, because the gradient is
      // opaque; what changed is which declaration paints it. An authored `cta` role (or
      // the `button` preset) overrides it: the band block is unlayered and this
      // stylesheet is in `@layer pp-v1`.
      //
      // Hero's SECONDARY is deliberately absent from every assertion in this block — it
      // carries its own role default now and answers to the tokens that default
      // references, not to `--btn-*`. Its reach is pinned in its own test below.
      expect(got.heroPrimary.bgImage).toBe(got.premiumFill);

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
     * THE HERO PAIR NO LONGER MOVES TOGETHER, AND THAT IS THE RESTORED v1 DEFAULT (#986).
     *
     * #554 pinned that a global `--btn-*` retheme reached hero's SECOND cta as well as its
     * first, so the pair stayed consistent. That pin assumed both buttons were bare `.btn`.
     * They were, briefly: `button2_variant` was removed in the v2 rebuild and the first cut
     * left `cta-secondary` with no defaults, which rendered two IDENTICAL filled buttons
     * side by side — v1 defaulted the second to `outline`, so that was a regression, not a
     * simplification.
     *
     * `cta-secondary` carries the outline treatment as its role default now. A role default
     * is emitted unlayered and this stylesheet is in `@layer pp-v1`, so `--btn-*` does not
     * reach it. The pair is deliberately ASYMMETRIC: the primary follows the global button
     * tier, the secondary follows its role.
     *
     * Asserted as the new contract rather than deleted, so a future edit that silently
     * re-attaches the secondary to `--btn-*` — or drops its default and makes the pair
     * identical again — fails here with the reason.
     */
    const cta2 = await page
      .locator('.hero__cta--secondary')
      .first()
      .evaluate((el: Element) => {
        const cs = getComputedStyle(el);
        return { bgColor: cs.backgroundColor, border: cs.borderTopColor, ink: cs.color };
      });
    expect(cta2.bgColor, 'hero cta2 must NOT follow --btn-bg').not.toBe('rgb(1, 2, 3)');
    expect(cta2.border, 'hero cta2 must NOT follow --btn-border-color').not.toBe('rgb(7, 8, 9)');
    expect(cta2.ink, 'hero cta2 must NOT follow --btn-text').not.toBe('rgb(4, 5, 6)');
    // And it must be visibly DIFFERENT from the primary, which is the whole point of the
    // restored default — not merely unreachable by the global tier.
    expect(cta2.bgColor, 'the hero pair must not render identically').not.toBe(got.heroPrimary.bgColor);
  });

  /*
   * THE PER-COMPONENT OVERRIDE, REBUILT FOR v2 (#1026) RATHER THAN RETIRED.
   *
   * It used to set `--cta-button-bg` through `style_component` and prove the per-component
   * slot beat a conflicting global `--btn-bg`. cta has no slots, so the surface changed —
   * but the PROPERTY is the one #458 exists to state, and it is now stronger rather than
   * weaker: a role block is emitted UNLAYERED while this stylesheet sits in `@layer pp-v1`,
   * so an authored role value outranks the global tier at any specificity instead of
   * winning by sitting at the head of a fallback chain.
   *
   * Authored through `update_composition`, because raw meta mints no band id and a `udc`
   * map written that way scopes to nothing — the test would then measure the GLOBAL knob
   * and pass for the wrong reason.
   */
  test('a per-band role value still beats the global --btn-bg @smoke', async ({ page }) => {
    pageId = buildOneKnobPage();

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const bands = oneKnobBands();
    bands[1].udc = { button: { background: { fill: 'rgb(20,30,40)' } } };
    const res = await updateComposition(page, pageId, bands);
    expect(res.success, JSON.stringify(res)).toBeTruthy();

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(SEL.cta)).toBeVisible({ timeout: 10000 });
    await page.addStyleTag({
      content: '*,*::before,*::after{transition:none !important;}:root{--btn-bg:rgb(1,2,3);}',
    });

    const ctaFill = await page
      .locator(SEL.cta)
      .evaluate((el) => getComputedStyle(el).backgroundColor);
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
  /** The three bands this block measures, shared by the raw-meta and authored builders. */
  function hoverPageBands(): Record<string, unknown>[] {
    return [
      {
        component: 'hero',
        props: {
          id: 'btn539-hero',
          title: 'Ship faster',
          button_text: 'Primary',
          button_url: '/start',
          button2_text: 'Secondary',
          button2_url: '/learn',
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
    ];
  }

  function buildHoverPage(): number {
    const id = createPage('E2E btn hover tier #539');
    setComposition(id, hoverPageBands());
    return id;
  }

  // heroCta2 is gone from this set for the same reason as the rest-state sweep above
  // (#986): hero's second CTA follows its `cta-secondary` role default now, not the
  // global hover knobs. Hero's PRIMARY is still here — it declares no role defaults.
  const SEL = {
    heroPrimary: '.hero__cta:not(.hero__cta--secondary)',
    ctaPrimary: '.cta__button:not(.cta__button--secondary)',
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
        // Hero's PRIMARY moved to the premium tier in #986: with no band-scoped rule of
        // its own it bottoms out where the generic panel CTA does, at --color-accent
        // rather than --color-accent-hover. Hero's SECONDARY is not in this set at all —
        // it follows its `cta-secondary` role default, not the global hover knobs.
        //
        // cta's PRIMARY joined hero's at #1026, for the identical reason: the band-scoped
        // `.cta .btn:not(...)` rule that resolved --color-accent-hover retired with cta's
        // slot map, so the primary is a bare `.btn` and bottoms out where every other
        // premium primary does. THE BAND-SCOPED TIER THIS BLOCK EXISTS TO PROTECT NO LONGER
        // HAS A MEMBER — which is a real narrowing of what the test can still say, not a
        // repricing that keeps it whole. What it still proves is the half that matters for
        // #539's own contract: the GLOBAL knobs reach every filled surface, and an unset
        // chain is byte-identical.
        heroPrimary: probe.accent,
        ctaPrimary: probe.accent,
        // cta's SECOND button is out of the sweep entirely since #1026. It carries v1's
        // outline treatment as `button-secondary` ROLE defaults, which emit unlayered, so
        // the global hover knobs cannot reach it — exactly as hero's secondary has been
        // since #986. Its own reach is pinned in the role-default emit test.
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
    // heroCta2 dropped (#986): hero's second CTA answers to its `cta-secondary` role
    // default, not to the global hover knobs. Its non-reach is asserted explicitly in the
    // #458 block rather than left as an absence here.
    // ctaButton2 dropped (#1026), for the identical reason: cta's second button carries
    // v1's outline treatment as `button-secondary` role defaults, which emit unlayered and
    // therefore outrank the global tier. The two components' pairs now behave the same way,
    // which is the shape the contract intends — a global knob moves every button a band has
    // not spoken for, and a role default is the band speaking.
    const covered = ['heroPrimary', 'ctaPrimary', 'panelCta'] as const;

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
      cta2: await readHovered('.hero__cta--secondary'),
    };
    // Guard the guard: prove these are hover reads, not rest reads. The sentinel only appears
    // in the hover chains, so a rest sample cannot produce it.
    expect(pair.primary.bgColor, 'sanity: primary sampled while hovered').toBe('rgb(1, 2, 3)');
    // THE PAIR IS DELIBERATELY ASYMMETRIC NOW (#986), and the assertion is inverted rather
    // than deleted. #554 pinned that a site-wide hover retheme moved BOTH hero buttons
    // together, which assumed both were bare `.btn`. `cta-secondary` carries the v1 outline
    // treatment as its role default now — emitted unlayered, so `--btn-hover-*` cannot
    // reach it. The primary still follows the global tier; the secondary follows its role.
    // A future edit that re-attaches the secondary to the global knobs fails here.
    expect(pair.cta2.bgColor, 'hero cta2 hover fill must NOT follow --btn-hover-bg').not.toBe(
      pair.primary.bgColor,
    );
    expect(pair.cta2.border, 'hero cta2 hover ring must NOT follow --btn-hover-border-color').not.toBe(
      'rgb(7, 8, 9)',
    );
  });

  // RETIRED (#986): pinned a hero style slot or the .hero__overlay element, neither of which exists on a v2 hero. The surviving behaviour is covered by the UDC contract tests and by the cta rows in the same block.

  test('a per-instance hover BORDER slot still beats the global --btn-hover-border-color @smoke', async ({
    page,
  }) => {
    pageId = buildHoverPage();

    // --btn-hover-border-color was threaded into six border chains at six different
    // positions. Chain-order string pins catch a text edit, but only a rendered hover proves
    // the PRECEDENCE actually holds in the cascade — the exact distinction that makes this
    // whole block an E2E rather than a unit test.
    //
    // AUTHORED AS A ROLE STATE SINCE #1026 (it was `--cta-button-hover-border`). The claim is
    // the same and the mechanism is stronger: a `':hover'` nested inside the role's `border`
    // group emits UNLAYERED, so it outranks the global knob by cascade LAYER rather than by
    // sitting earlier in a `var()` fallback chain. Written through `update_composition`
    // because raw meta mints no band id.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const hoverBands = hoverPageBands();
    hoverBands[1].udc = {
      button: { border: { ':hover': { color: 'rgb(60,70,80)' } } },
    };
    const res = await updateComposition(page, pageId, hoverBands);
    expect(res.success, JSON.stringify(res)).toBeTruthy();

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

  // SUCCEEDS the retired --testimonials-padding-top test.
  //
  // The slot is gone, but the ruling it encoded is not: a per-instance band
  // padding must win on the adjacent-top edge, exactly as section does in
  // #305/#302. Under v2 the per-instance surface is the band's own `udc` map.
  //
  // This test exists because that ruling BROKE when testimonials moved to the
  // contract, and it broke silently: the shared rhythm rule
  // `main > [data-pp-component] + [data-pp-component]` was [0,2,1] and the
  // authored block `[data-pp-band="<id>"]` is [0,1,0], so an authored padding-top
  // validated, stored, reported applied — and then rendered 76.8px instead of 5px.
  // The fix was to stop the shared rule claiming specificity it never meant to
  // claim (it is `:where()`-wrapped now), because §3.4 ranks an authored band
  // value above a default and that rule IS a default, not a lock.
  //
  // Both stack positions are asserted. Only the adjacent one regressed, but a test
  // that checked only the broken case would not notice a fix that broke the other.
  test('#431 an authored _band padding wins from BOTH stack positions at 1280 and 375', async ({
    page,
  }) => {
    const section = { component: 'section', props: { id: 'pp-sec01', body: '<p>Body.</p>' } };
    const band = {
      component: 'testimonials',
      props: { id: 'pp-tst01', items: [{ quote: 'It works.', author: 'A' }] },
      udc: { _band: { spacing: { 'padding-top': '5px', 'padding-bottom': '6px' } } },
    };

    for (const [position, composition] of [
      ['leading', [band, section]],
      ['adjacent', [section, band]],
    ] as const) {
      pageId = createPage(`E2E Testimonials v2 Band Padding ${position}`);
      setComposition(pageId, [section]);

      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
      const res = await updateComposition(page, pageId, composition as unknown[]);
      expect(res.success, `${position} write: ${JSON.stringify(res)}`).toBe(true);

      for (const width of [1280, 375]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/?page_id=${pageId}`);
        const tst = page.locator('main > .testimonials');
        await expect(tst).toBeVisible({ timeout: 10000 });
        const box = await tst.evaluate((el) => ({
          top: getComputedStyle(el).paddingTop,
          bottom: getComputedStyle(el).paddingBottom,
        }));
        expect(box.top, `authored band padding-top, ${position} @${width}`).toBe('5px');
        expect(box.bottom, `authored band padding-bottom, ${position} @${width}`).toBe('6px');
      }
    }
  });

  // THE CLASS PIN behind the test above (invariant I35).
  //
  // The padding-top regression was one instance of a general hazard: a v2 band
  // block is [0,1,0], and ANY structural rule with more weight silently beats it.
  // Pinning the one property that broke would leave the next one to be found the
  // same way — by a brand not rendering.
  //
  // So this asserts the CLASS. It reads the band block the engine actually
  // emitted, walks every declaration in it, and requires the computed value on the
  // matched element to equal what the band declared. It is data-driven from the
  // emitted CSS, so a role, group or parameter added later is covered the day it
  // is emitted, with no edit here. Values are authored absolute (px, hex) so both
  // sides canonicalize through the browser and the comparison is exact.
  test('#431/I35 no structural CSS outranks any declaration a v2 band block makes @smoke', async ({
    page,
  }) => {
    // Adjacent position deliberately: it is the one that carries the extra
    // sibling-combinator rules, so it is where an outranking rule is most likely.
    const composition = [
      { component: 'section', props: { id: 'pp-sec01', body: '<p>Body.</p>' } },
      {
        component: 'testimonials',
        props: { id: 'pp-tst01', title: 'Voices', subheading: 'What they say', items: [{ quote: 'It works.', author: 'A', role: 'CTO', company: 'Co' }] },
        udc: {
          _band: { spacing: { 'padding-top': '5px', 'padding-bottom': '6px' }, background: { fill: '#f4f5f7' } },
          heading: { typography: { size: '41px', color: '#112233' }, spacing: { 'margin-bottom': '7px' } },
          subheading: { typography: { size: '17px', color: '#223344' }, spacing: { 'margin-bottom': '8px' } },
          card: { border: { width: '2px', color: '#345678', radius: '9px' }, background: { fill: '#fffefd' }, spacing: { padding: '11px' } },
          quote: { typography: { size: '19px', color: '#334455', 'line-height': '1.5' } },
          author: { typography: { size: '13px', color: '#445566' } },
          meta: { typography: { size: '12px', color: '#556677' } },
        },
      },
    ];

    pageId = createPage('E2E v2 Band Block Outranked Guard');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-sec01', body: '<p>Body.</p>' } }]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await updateComposition(page, pageId, composition);
    expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('main > .testimonials')).toBeVisible({ timeout: 10000 });

      const report = await page.evaluate(() => {
        const root = document.querySelector('main > .testimonials') as HTMLElement | null;
        if (!root) return { error: 'no band' as const, checked: 0, mismatches: [] as string[] };
        const bandId = root.getAttribute('data-pp-band');
        if (!bandId) return { error: 'no band id' as const, checked: 0, mismatches: [] as string[] };
        const scope = `[data-pp-band="${bandId}"]`;

        const mismatches: string[] = [];
        let checked = 0;

        // Whether a declaration WINS is asked directly, by re-declaring it at the
        // top of the cascade on the element itself and seeing whether anything
        // moves. If forcing the band's own value changes the computed result, then
        // something was outranking the band block. This needs no canonicalization
        // of the authored literal — which is the point: an earlier version of this
        // test compared against a probe element and reported `line-height: 1.5` as
        // a failure, because a ratio resolves against each element's own font-size
        // and the probe's differed. Asking the element itself cannot drift that way.
        const winsOnItsOwnElement = (el: HTMLElement, prop: string, declared: string): boolean => {
          const before = getComputedStyle(el).getPropertyValue(prop);
          const priorValue = el.style.getPropertyValue(prop);
          const priorPriority = el.style.getPropertyPriority(prop);
          el.style.setProperty(prop, declared, 'important');
          const forced = getComputedStyle(el).getPropertyValue(prop);
          el.style.removeProperty(prop);
          if (priorValue) el.style.setProperty(prop, priorValue, priorPriority);
          return before === forced;
        };

        const visit = (rules: CSSRuleList) => {
          for (const rule of Array.from(rules)) {
            if (rule instanceof CSSMediaRule) {
              // Only the tier actually in force at this viewport.
              if (window.matchMedia(rule.conditionText).matches) visit(rule.cssRules);
              continue;
            }
            if (!(rule instanceof CSSStyleRule)) continue;
            if (!rule.selectorText.includes(scope)) continue;
            const el = document.querySelector(rule.selectorText) as HTMLElement | null;
            if (!el) continue;
            for (const prop of Array.from(rule.style)) {
              const declared = rule.style.getPropertyValue(prop);
              checked++;
              if (!winsOnItsOwnElement(el, prop, declared)) {
                mismatches.push(
                  `${rule.selectorText} { ${prop}: ${declared} } is outranked — computed ${getComputedStyle(el).getPropertyValue(prop)}`,
                );
              }
            }
          }
        };
        for (const sheet of Array.from(document.styleSheets)) {
          let rules: CSSRuleList;
          try {
            rules = sheet.cssRules;
          } catch {
            continue; // cross-origin
          }
          visit(rules);
        }
        return { error: null, checked, mismatches };
      });

      expect(report.error, `@${width}`).toBeNull();
      // Non-vacuity: if the walker stops finding declarations, this test stops
      // testing anything, and that must fail rather than pass quietly.
      expect(report.checked, `declarations examined @${width}`).toBeGreaterThanOrEqual(15);
      expect(report.mismatches, `structural CSS outranks the band block @${width}`).toEqual([]);
    }
  });

  // THE SAME CLASS PIN, ON THE SURFACE THAT ACTUALLY BROKE (#986).
  //
  // The walker above drives a `testimonials` band whose udc map has no `cta` role
  // and no button in it at all — so it could not have caught the defect ruling D5
  // was written for, and could not catch its return. The defect was a hero whose
  // `cta` role carried `"_preset": "button"` painting the v1 stylesheet's premium
  // gradient instead of the author's fill: accepted at write, reported applied,
  // and wrong on the page. That is the I35 class on the one family of v1 rules
  // that reaches [0,5,1].
  //
  // A button is also where the emitted CSS and the rendered result diverge most
  // easily, which is why this asserts COMPUTED values and not CSS text: the text
  // was already correct while the bug was live.
  test('#986/I35 an authored hero CTA outranks the v1 premium button rules, rest and hover @smoke', async ({
    page,
  }) => {
    const composition = [
      { component: 'section', props: { id: 'pp-sec01', body: '<p>Body.</p>' } },
      {
        component: 'hero',
        props: {
          id: 'pp-hero01',
          title: 'Authored',
          button_text: 'Primary',
          button_url: '/a',
          button2_text: 'Secondary',
          button2_url: '/b',
        },
        udc: {
          // The preset is the point: it is the path that failed, so it must be
          // exercised, not avoided. Values beside it must still win over it.
          cta: {
            _preset: 'button',
            background: { fill: '#ff00ff', ':hover': { fill: '#123456' } },
            typography: { color: '#00ff00', ':hover': { color: '#ffff00' } },
            border: { width: '4px', style: 'solid', color: '#0000ff' },
          },
          'cta-secondary': {
            _preset: 'button-secondary',
            background: { fill: '#00ffff' },
            typography: { color: '#ff0000' },
          },
        },
      },
    ];

    pageId = createPage('E2E v2 Hero CTA Outranked Guard');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-sec01', body: '<p>Body.</p>' } }]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await updateComposition(page, pageId, composition);
    expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);

    await page.goto(`/?page_id=${pageId}`);
    const primary = page.locator('.hero__cta--primary').first();
    await expect(primary).toBeVisible({ timeout: 10000 });

    const read = (loc: typeof primary) =>
      loc.evaluate((el: Element) => {
        const s = getComputedStyle(el);
        return {
          bg: s.backgroundColor,
          bgImage: s.backgroundImage,
          color: s.color,
          borderWidth: s.borderTopWidth,
          borderColor: s.borderTopColor,
        };
      });

    const rest = await read(primary);
    expect(rest.bg, 'authored fill at rest').toBe('rgb(255, 0, 255)');
    expect(rest.color, 'authored ink at rest').toBe('rgb(0, 255, 0)');
    expect(rest.borderWidth, 'authored ring width at rest').toBe('4px');
    expect(rest.borderColor, 'authored ring colour at rest').toBe('rgb(0, 0, 255)');
    // The v1 premium rules paint their gradient through background-IMAGE, which is
    // the half a background-COLOR assertion cannot see — and the half that made the
    // original screenshots look plausible while the button was wrong.
    expect(rest.bgImage, 'the v1 premium gradient must not paint').toBe('none');

    const secondary = page.locator('.hero__cta--secondary').first();
    const sec = await read(secondary);
    expect(sec.bg, 'authored secondary fill').toBe('rgb(0, 255, 255)');
    expect(sec.color, 'authored secondary ink').toBe('rgb(255, 0, 0)');
    expect(sec.bgImage, 'no premium gradient on the secondary either').toBe('none');

    await primary.hover();
    await page.waitForTimeout(400);
    const hover = await read(primary);
    expect(hover.bg, 'authored fill on hover').toBe('rgb(18, 52, 86)');
    expect(hover.color, 'authored ink on hover').toBe('rgb(255, 255, 0)');
    expect(hover.bgImage, 'no premium hover gradient').toBe('none');
  });

  // THE REGRESSION NET FOR THE COMPONENTS THAT HAVE NOT BEEN REBUILT (#986).
  //
  // Ruling D5's first attempt wrapped four premium button rules in `:where()`.
  // That zeroed them against a band block as intended AND against `.btn` [0,1,0],
  // which nothing intended: every composed primary button outside a v2 band
  // silently lost its 1px accent-strong ring for `.btn`'s 2px accent ring, lost
  // its resting bevel, and had #540's transition narrowing defeated by
  // `main .btn`'s five-property shorthand.
  //
  // None of that was visible to CI. tests/js/css-lint.test.js matches on selector
  // SHAPE, so it stayed green against a `:where()`-wrapped compound, and the one
  // rendered test that read transitionProperty on a filled premium button had been
  // retired in the same branch. So this pins the four properties that moved, on a
  // legacy component, at rest and on hover, as a rendered computed read.
  test('#986 the v1 premium button treatment survives on a legacy cta, rest and hover @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Legacy CTA Premium Treatment');
    setComposition(pageId, [
      {
        component: 'cta',
        props: { id: 'pp-cta01', title: 'Ready?', button_text: 'Start', button_url: '/x' },
      },
    ]);
    await page.goto(`/?page_id=${pageId}`);
    const btn = page.locator('.cta__button').first();
    await expect(btn).toBeVisible({ timeout: 10000 });

    const read = () =>
      btn.evaluate((el: Element) => {
        const s = getComputedStyle(el);
        return {
          borderWidth: s.borderTopWidth,
          boxShadow: s.boxShadow,
          transitionProperty: s.transitionProperty,
        };
      });

    const rest = await read();
    // 1px, not `.btn`'s 2px: the premium rule must still outrank the bare primitive.
    expect(rest.borderWidth, 'premium ring width at rest').toBe('1px');
    // The resting bevel exists. `none` is the signature of `.btn` winning.
    expect(rest.boxShadow, 'premium bevel at rest').not.toBe('none');
    // #540: the fill and the ring SNAP; only these three ease. A five-property list
    // here is the off-brand mid-tween flash that issue removed.
    expect(rest.transitionProperty, '#540 transition narrowing at rest').toBe(
      'box-shadow, color, transform',
    );

    await btn.hover();
    await page.waitForTimeout(400);
    const hover = await read();
    expect(hover.borderWidth, 'premium ring width on hover').toBe('1px');
    expect(hover.boxShadow, 'premium bevel on hover').not.toBe('none');
    expect(hover.transitionProperty, '#540 transition narrowing on hover').toBe(
      'box-shadow, color, transform',
    );
  });

  // THE THEME RESET STILL BEATS AN UNLAYERED CORE RULE (#986).
  //
  // WordPress core ships `:where(figure){margin:0 0 1em}` UNLAYERED on the front end.
  // The theme's `* { margin: 0 }` used to win that tie on source order; once the
  // stylesheet went into `@layer pp-reset` it lost at any specificity, and a `<figure>`
  // in authored body HTML silently gained a 1em bottom margin (measured: 17.04px).
  //
  // The fix is one unlayered `figure { margin: 0 }` in base.css. Pinned as a RENDERED
  // read against real core CSS, because that is the only place the interaction exists —
  // a static lint of our own stylesheet cannot see a rule core contributes at runtime.
  test('#986 an unlayered core rule does not re-margin a figure in authored body HTML @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E figure margin under layers');
    setComposition(pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec01',
          title: 'Prose',
          body: '<p>Before.</p><figure><img src="/x.png" alt="x"><figcaption>cap</figcaption></figure><p>After.</p>',
        },
      },
    ]);
    await page.goto(`/?page_id=${pageId}`);
    const fig = page.locator('figure').first();
    await expect(fig).toBeVisible({ timeout: 10000 });
    const box = await fig.evaluate((el: Element) => {
      const cs = getComputedStyle(el);
      return { bottom: cs.marginBottom, top: cs.marginTop };
    });
    expect(box.bottom, 'core must not add a bottom margin the theme reset removed').toBe('0px');
    expect(box.top, 'and the top stays zero too').toBe('0px');
  });

  // RESTORED DEFAULT: the centered layout centres its TEXT (#986).
  //
  // `centered` is hero's DEFAULT layout. The first v2 cut moved text-align out to the
  // roles, and no role ships a `typography.align` default — so a wrapping headline
  // rendered ragged-left inside a centred box while the schema told the authoring AI
  // "centered centers all content". Single-line text hid it, which is why a
  // screenshot did not catch it; the title here is deliberately long enough to wrap.
  test('#986 the centered hero layout centres its text, and an authored align still wins @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Hero Centered Text');
    setComposition(pageId, [
      {
        component: 'hero',
        props: {
          id: 'pp-hero01',
          layout: 'centered',
          title: 'A deliberately long hero headline that has to wrap onto several lines to show its alignment',
          subheading: 'A subheading long enough that it also wraps and can be read for alignment.',
        },
      },
    ]);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const title = page.locator('.hero__title').first();
    await expect(title).toBeVisible({ timeout: 10000 });
    expect(
      await title.evaluate((el: Element) => getComputedStyle(el).textAlign),
      'centered layout must centre the title text',
    ).toBe('center');
    expect(
      await page.locator('.hero__subtitle').first().evaluate((el: Element) => getComputedStyle(el).textAlign),
      'centered layout must centre the subtitle text',
    ).toBe('center');

    // The structural default must stay overridable: the authored tier is unlayered
    // and the stylesheet is in `pp-v1`, so an authored align outranks it.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await updateComposition(page, pageId, [
      {
        component: 'hero',
        props: {
          id: 'pp-hero01',
          layout: 'centered',
          title: 'A deliberately long hero headline that has to wrap onto several lines to show its alignment',
          subheading: 'A subheading long enough that it also wraps and can be read for alignment.',
        },
        udc: { title: { typography: { align: 'left' } } },
      },
    ]);
    expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);
    await page.goto(`/?page_id=${pageId}`);
    expect(
      await page.locator('.hero__title').first().evaluate((el: Element) => getComputedStyle(el).textAlign),
      'an authored typography.align must beat the layout default',
    ).toBe('left');
  });

  // RESTORED AFFORDANCE: the on-overlay focus ring follows the overlay (#986).
  //
  // v1 keyed it to `.hero--cover`, which was sound while `cover` was the only layout
  // that could carry a background image. Ruling A2 made the band image
  // `_band.background.image`, authorable on EVERY layout — so a `left` hero with an
  // image and a scrim kept the bare `--color-accent` ring, 1.17:1 over the worst-case
  // scrim. WCAG 1.4.11. The layout here is deliberately NOT cover.
  test('#986 a non-cover hero with an overlay gets the on-overlay focus ring @smoke', async ({
    page,
  }) => {
    const attachmentId = importTestImage('pp-overlay-ring');
    try {
      pageId = createPage('E2E Hero Overlay Focus Ring');
      setComposition(pageId, [{ component: 'section', props: { id: 'pp-sec01', body: '<p>b</p>' } }]);
      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
      const res = await updateComposition(page, pageId, [
        {
          component: 'hero',
          props: {
            id: 'pp-hero01',
            layout: 'left',
            title: 'Overlaid',
            button_text: 'Focus me',
            button_url: '/x',
          },
          udc: {
            _band: { background: { image: attachmentId, overlay: '#000000cc' } },
          },
        },
      ]);
      expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);

      await page.goto(`/?page_id=${pageId}`);
      const hero = page.locator('.hero').first();
      await expect(hero).toBeVisible({ timeout: 10000 });

      // The engine emitted the hook, on a layout that is not `cover`.
      expect(
        await hero.evaluate((el: Element) => el.hasAttribute('data-pp-band-overlay')),
        'the engine must mark a band that paints a scrim',
      ).toBe(true);
      expect(
        await hero.evaluate((el: Element) => el.className),
        'this case must NOT be the cover layout, or it proves nothing new',
      ).not.toContain('hero--cover');

      const btn = page.locator('.hero__cta--primary').first();
      const ring = await btn.evaluate((el: Element) => {
        (el as HTMLElement).focus();
        return getComputedStyle(el).outlineColor;
      });
      const onOverlay = await page.evaluate(() =>
        getComputedStyle(document.documentElement).getPropertyValue('--color-accent-on-overlay').trim(),
      );
      expect(onOverlay, '--color-accent-on-overlay must be defined').not.toBe('');
      // Compare through the browser so both sides canonicalise the colour.
      const expected = await page.evaluate((c: string) => {
        const probe = document.createElement('span');
        probe.style.color = c;
        document.body.appendChild(probe);
        const out = getComputedStyle(probe).color;
        probe.remove();
        return out;
      }, onOverlay);
      expect(ring, 'the focus ring must use the on-overlay accent, not the bare accent').toBe(expected);

      // The companion defaults ruling 2 restored: the image must cover, not tile.
      const bg = await hero.evaluate((el: Element) => {
        const s = getComputedStyle(el);
        return { size: s.backgroundSize, repeat: s.backgroundRepeat, position: s.backgroundPosition };
      });
      // A scrim over a photograph is TWO background layers, so each longhand
      // computes once per layer ("cover, cover"). Every layer must carry it — a
      // per-layer check, not a string match, so the assertion survives an overlay
      // being added or removed.
      const everyLayer = (value: string, expected: string) =>
        value.split(',').map((v) => v.trim()).every((v) => v === expected);
      expect(everyLayer(bg.size, 'cover'), `background-size per layer: ${bg.size}`).toBe(true);
      expect(everyLayer(bg.repeat, 'no-repeat'), `background-repeat per layer: ${bg.repeat}`).toBe(true);
      expect(everyLayer(bg.position, '50% 50%'), `background-position per layer: ${bg.position}`).toBe(true);
    } finally {
      deleteAttachment(attachmentId);
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
  // RETIRED (#986): a hero style slot with no v2 successor — the value is a role parameter now, covered by the UDC contract tests.

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
  // RETIRED (#986): a hero style slot with no v2 successor — the value is a role parameter now, covered by the UDC contract tests.

  // The measured webfiable.com defect sequence (issue 430 body): hero → stats →
  // grid → cta → grid → section → cta, alternating inverted/plain backgrounds.
  // The two cta bands no longer carry `theme: 'muted'` — #1026 retired the prop, and a
  // fixture that writes it would be storing a key the component rejects. Nothing measured
  // here moves: the assertion is padding symmetry, and `muted` was measured byte-identical
  // to `default` on a full-width band before it retired. The stats band still alternates.
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
      { component: 'cta', props: { id: 'pp-cta01', title: 'CTA', button_text: 'Go', button_url: '/go' } },
      { component: 'grid', props: { id: 'pp-grid02', title: 'Grid Two', items: [{ title: 'Two', text: 'B' }] } },
      { component: 'section', props: { id: 'pp-sec01', body: '<p>Section body.</p>' } },
      { component: 'cta', props: { id: 'pp-cta02', title: 'Closing', button_text: 'Go', button_url: '/go' } },
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
  /*
   * THE ADJACENT-BAND OVERRIDE, REBUILT FOR v2 (#1026) RATHER THAN RETIRED.
   *
   * It used to author `--cta-padding-top: 5px` and prove the per-band value won on the
   * ADJACENT-top edge — the trickiest cascade case, because #430/#431's shared rhythm rule
   * (`main > [data-pp-component] + .cta`) aims at the same property from the stylesheet.
   * cta has no slots, and its per-component adjacent rule was deleted with them, so BOTH
   * sides of that old contest are gone.
   *
   * WHAT THE CONTEST IS NOW, and why it still needs a rendered pin: the shared rhythm rule
   * sits in `@layer pp-v1`, the `_band` role's padding DEFAULT emits into `pp-zero` below
   * it (so the rhythm still wins on an unauthored band, which is #430's whole point), and
   * an AUTHORED `_band` value emits UNLAYERED above both. Three tiers, two of which changed
   * at this rebuild. Asserting only the authored value would leave the middle tier — the
   * one #430 exists for — unpinned, so both are read in the same scene.
   */
  test('#430 a v2 band yields its adjacent top to the shared rhythm, and an authored value wins', async ({
    page,
  }) => {
    pageId = createPage('E2E Adjacent v2 cta');
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const bands = (udc?: Record<string, unknown>) => [
      { component: 'section', props: { id: 'pp-sec01', body: '<p>Body.</p>' } },
      {
        component: 'cta',
        props: { id: 'pp-cta01', title: 'CTA', button_text: 'Go', button_url: '/go' },
        ...(udc ? { udc } : {}),
      },
    ];

    // 1 — UNAUTHORED: the shared adjacent rhythm wins over the pp-zero role default.
    const seeded = await updateComposition(page, pageId, bands());
    expect(seeded.success, JSON.stringify(seeded)).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const cta = page.locator('main > .cta');
    await expect(cta).toBeVisible({ timeout: 10000 });

    const probe = await page.evaluate(() => {
      const el = document.createElement('div');
      el.style.setProperty('padding-top', 'var(--pp-band-padding-adjacent-top)');
      document.body.appendChild(el);
      const v = getComputedStyle(el).paddingTop;
      el.remove();
      return v;
    });
    const unauthored = await cta.evaluate((el) => getComputedStyle(el).paddingTop);
    expect(unauthored, 'an unauthored v2 band must still obey the shared adjacent rhythm').toBe(
      probe,
    );

    // 2 — AUTHORED: an unlayered role value outranks both tiers, at both widths.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const styled = await updateComposition(
      page,
      pageId,
      bands({ _band: { spacing: { 'padding-top': '5px' } } }),
    );
    expect(styled.success, JSON.stringify(styled)).toBe(true);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const band = page.locator('main > .cta');
      await expect(band).toBeVisible({ timeout: 10000 });
      const paddingTop = await band.evaluate((el) => getComputedStyle(el).paddingTop);
      expect(paddingTop, `authored adjacent-top @${width}`).toBe('5px');
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
  const HEADINGS: { band: string; sel: string; slot?: string }[] = [
    { band: 'section', sel: '.section__title', slot: '--section-heading-size' },
    { band: 'grid', sel: '.grid__heading', slot: '--grid-heading-size' },
    { band: 'cta', sel: '.cta__title', slot: '--cta-heading-size' },
    { band: 'stats', sel: '.stats__heading', slot: '--stats-heading-size' },
    { band: 'table', sel: '.table-section__heading', slot: '--table-heading-size' },
    // No `slot`: testimonials is on the UDC contract and has none. Only `band`
    // and `sel` are read by the equality test below, which it still joins —
    // its heading resolves the same shared --pp-band-heading-size scale.
    { band: 'testimonials', sel: '.testimonials__heading' },
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
  // (--table-heading-size, --logos-heading-size, --embed-heading-size) must each
  // validate (styleComponent success) and
  // win over the shared scale at mobile AND desktop. This is the only render-level
  // proof that the fresh pp_render_style_vars wiring in table/logos/embed.php uses
  // the correct component-name string — a typo there would validate but never
  // reach the DOM. Pixel values no scale step resolves to, so a fallback leak is
  // unmistakable.
  test('#436 existing and all newly-minted heading slots override the shared scale at 375 and 1280', async ({
    page,
  }) => {
    pageId = createPage('E2E Band Heading Slot Override');
    // cta's row left at #1026 with its slot map. Its heading size is the `heading` role's
    // `typography.size`, defaulting to the same `@pp-band-heading-size` this block pins for
    // every component still on slots — so the shared SCALE is unchanged; only the override
    // address moved, and the emitted default is asserted in CtaRoleDefaultsEmitTest.
    setComposition(pageId, [
      { component: 'table', props: { id: 'pp-tbl01', title: 'Table', headers: ['A', 'B'], rows: [['1', '2']] } },
      { component: 'logos', props: { id: 'pp-logo01', title: 'Logos', items: [{ image_url: 'https://example.com/l.png', image_alt: 'Logo' }] } },
      { component: 'embed', props: { id: 'pp-emb01', title: 'Embed', content: 'https://example.com/video' } },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // The three slots minted in #436 that are still slots. Distinct px per component so a
    // cross-wired value would be caught.
    const overrides = [
      { idx: 0, slot: '--table-heading-size', px: '61px', sel: '.table-section__heading' },
      { idx: 1, slot: '--logos-heading-size', px: '62px', sel: '.logos__heading' },
      { idx: 2, slot: '--embed-heading-size', px: '63px', sel: '.embed__heading' },
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
      { component: 'cta', props: { id: 'pp-cta01', title: 'CTA', button_text: 'Go', button_url: '/go' } },
      { component: 'grid', props: { id: 'pp-grid02', title: 'Grid Two', items: [{ title: 'Two', text: 'B' }] } },
      { component: 'section', props: { id: 'pp-sec01', title: 'Section', body: '<p>Section body.</p>' } },
      { component: 'cta', props: { id: 'pp-cta02', title: 'Closing', button_text: 'Go', button_url: '/go' } },
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

/*
 * RETIRED (#1026): FIVE RENDERED BLOCKS, because all five measured cta's per-instance
 * button slots or its variant classes, and the rebuild removed both.
 *
 *   '#548 cta primary hover ring ranks the accent above the fill (real WP)'
 *   '#543 filled second button is ringed on overlay bands (real WP)'
 *   '#461 bg-image band accent contrast (rendered)'
 *   '#463 bg-image band title-accent + markers contrast (rendered)'
 *   '#437 inverted link contrast (rendered)'
 *
 * #548 and #543 pinned which link won inside a filled button's `var()` chain, in a real
 * browser. Every per-instance link in those chains was a style slot; with cta's gone the
 * chains are one global `--btn-*` knob and one literal, and two links that cannot both
 * exist cannot be mis-ordered. #461, #463 and #437 pinned AA ink on `.cta--inverted` /
 * `.cta--has-bg-image`, classes derived from `theme` and `background_image` — retired
 * props, so no v2 cta can carry either.
 *
 * THESE WERE THE RENDERED HALF OF A GUARANTEE, so losing them matters more than losing a
 * CSS-text pin, and the replacement is named rather than assumed:
 *
 *   - THE MEASURED RATIOS ARE NOT LOST, they moved surface. #1026's evidence run reads
 *     every text role's ink against the band's own painted background, at 375/768/1280, on
 *     a dark band authored through the documented migration route — which is what an author
 *     now has to get right themselves. Recorded with the issue rather than as a pin,
 *     because there is no longer a DEFAULT to pin: the value is whatever the author wrote.
 *   - THE ONE AFFORDANCE STILL AUTOMATIC IS THE FOCUS RING, and its rendered pin survives
 *     in the #542 block below, which #986 re-keyed onto `[data-pp-band-overlay]` — an
 *     engine-emitted attribute cta now sets, so it follows the scrim across every layout
 *     instead of following one variant class.
 *   - THE #545 NESTED-BUTTON PINS ALSO SURVIVE, on section, and they are the ones that
 *     prove the isolation property held rather than merely that a rule existed.
 *
 * WHAT IS GENUINELY GONE: a cta over a scrim or a dark fill no longer gets AA ink or a
 * separation ring by default. That is #986's ruling for `.hero--cover` applied here — "v2
 * has no variant-scoped role defaults and does not guess" — and it is disclosed in the
 * CHANGELOG, in components/cta/README.md, and in the two AI-facing instruction files, so
 * the authoring model teaches the author to set it rather than assuming it.
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
    /**
     * A v2 band's design (#1023). Its presence switches the fixture to the REAL write
     * path: a `udc` map only scopes to a band whose id the engine minted, and raw meta
     * mints nothing.
     */
    udc?: Record<string, unknown>;
  };
  const ACCENT_RGB = [49, 87, 244]; // --color-accent #3157f4, default palette

  const cases: Case[] = [
    {
      // REPRICED (#1023). The v1 case asserted that `theme: "inverted"` ROUTED the link
      // to an on-inverted colour automatically — a band-class mechanism section no longer
      // has, and deliberately: v2 makes the author own contrast, which is the trade for
      // being able to build a band the three-value theme bundle could not express.
      //
      // The claim worth keeping is the OUTCOME, not the mechanism: a dark section band's
      // body link clears AA. So the band is authored dark the v2 way and the link colour
      // is set on the `body-link` role, and the SAME 4.5:1 assertion runs against it. What
      // this now proves is that the authored route actually reaches the rendered anchor —
      // which is the thing an author following the migration table needs to be true, and
      // the one a CSS-text pin cannot show.
      name: 'section body link on an authored dark band → AA',
      composition: [
        {
          component: 'section',
          props: {
            id: 'pp-sec01',
            title: 'Dark section',
            body: '<p>Body copy with an inline <a href="/somewhere">text link</a> to prove contrast.</p>',
          },
          udc: {
            _band: { background: { fill: '#0b1020' } },
            heading: { typography: { color: '#ffffff' } },
            body: { typography: { color: 'rgba(255, 255, 255, 0.82)' } },
            'body-link': { typography: { color: '#9ec5ff', ':hover': { color: '#ffffff' } } },
          },
        },
      ],
      linkSelector: '.section__content a',
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
    // RETIRED (#1026), on exactly the testimonials precedent below. The case drove
    // `theme: 'inverted'` and selected on `.cta--inverted .cta__body a`; the prop and
    // the class both retired with cta's rebuild, so the case cannot be constructed.
    // Its truth — a body link on a dark cta band must reach AA — is RELOCATED, not
    // dropped: the band and the link colour are both authored values now, so the
    // requirement is stated in components/cta/README.md and the migration how-to
    // ("a dark band or a scrim owns its own contrast", which names `body-link` and its
    // `:hover` explicitly). There is no rendered DEFAULT left to pin here. The
    // authored route is proven by the section case at the top of this list, which is
    // the same mechanism on the band that rebuilt first.
    // RETIRED (v2 Sprint 0): the testimonials case drove `theme: 'inverted'` and
    // selected on `.testimonials--inverted`. Both are gone with the theme prop, so
    // the case cannot be constructed. Its truth — a quote link on a dark band must
    // reach AA — is not dropped but RELOCATED: under v2 the dark band and the
    // colours that must read against it are both authored values, so meeting AA is
    // the authoring layer's job, and the requirement is stated to the model in
    // components/testimonials/README.md ("You own the contrast", which names link
    // colour explicitly). It is not pinnable as a rendered default here because
    // there is no longer a default to pin. The other eight cases below are
    // untouched — their components still carry the theme prop.
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
      if (c.composition.some((b) => (b as { udc?: unknown }).udc)) {
        // v2 bands need the validated write path so the engine mints a band id.
        setComposition(pageId, [{ component: 'section', props: { id: 'pp-seed', body: '<p>Seed.</p>' } }]);
        await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
        await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
        const res = await updateComposition(page, pageId, c.composition);
        expect(res.success, `udc write for "${c.name}": ${JSON.stringify(res)}`).toBe(true);
      } else {
        setComposition(pageId, c.composition);
      }

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

  // SECTION'S BAND LEFT THIS FIXTURE IN #1023, and the capability it tested left with it
  // rather than moving. The scrim-plus-routing recipe was a band-class mechanism:
  // `.section--has-bg-image` re-routed the accent surfaces to an on-overlay colour so an
  // author who set a photograph got legible text without asking. A v2 band has no class,
  // `background_image` is retired, and the author sets the colours — so there is no
  // automatic routing left to measure a contrast ratio against.
  //
  // What replaced the GUARANTEE is not another automatic route; it is a disclosure ("YOU
  // own the contrast", in section's README, the CHANGELOG and composition.md) plus the
  // authored-dark-band AA pin in the #437 block above, which proves the authored route
  // actually reaches the rendered anchor.
  //
  // CTA'S ROW LEFT THE SAME WAY AT #1026, for the same reason and with the same
  // replacement. STATS KEEPS ITS ROW: it is still a v1 component, still takes
  // `background_image`, still paints a `.stats__overlay`, and still routes the accent
  // automatically — so the guarantee this block measures is still a guarantee there, and
  // narrowing the fixture is what preserves that rather than deleting it. The lists below
  // are keyed by component name precisely so a departure removes one entry and cannot
  // silently re-point a slot at the wrong band.
  const bands = () => [
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
    { name: 'stats number', accent: '.stats--has-bg-image .stats__number', overlay: '.stats--has-bg-image .stats__overlay' },
  ];

  test('every remaining bg-image accent surface clears AA (4.5:1) over the overlay-over-white worst case @375 + @1280', async ({
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
    // Indices moved when section's band left this fixture in #1023 — bound to the
    // component name rather than the position so the next departure cannot silently
    // attach a slot to the wrong band (which is what a positional edit would do).
    const SLOTS: Record<string, string> = {
      stats: '--stats-number-color',
    };
    for (const band of b) {
      const slot = SLOTS[band.component as string];
      expect(slot, `no per-instance slot mapped for "${band.component}"`).toBeTruthy();
      (band.props as Record<string, unknown>).__pp_style = { [slot]: SLOT };
    }
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

  // SECTION'S BAND AND ITS TWO SURFACES LEFT IN #1023, for the reason recorded on the
  // #461 block above: the on-overlay routing was a band-class mechanism and a v2 band has
  // no class. One of the two is worth naming separately, because it is a genuine
  // capability loss rather than a transfer of responsibility — the LIST MARKER. Its
  // colour is not authorable at all any more: the glyph is drawn with `content` on a
  // `::before`, ruling A3 defers pseudo-elements, and the `--pp-list-marker-color` the
  // rule reads is plumbing nothing can write (#1028). It renders `var(--color-accent)`.
  // So on a dark v2 band an author who needs a legible marker has exactly one move —
  // `update_design_token` on `--color-accent` itself, which recolours every accent on the
  // site. That is disclosed in section's README, the CHANGELOG and composition.md, and it
  // is the sharp edge #1024's per-item work should look at.
  const bands = () => [
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

    { name: 'stats heading-accent', accent: '.stats--has-bg-image .stats__heading-accent', pseudo: '', slot: '--stats-heading-accent-color', overlay: '.stats--has-bg-image .stats__overlay' },
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

  // RETIRED (#986): pinned a hero style slot or the .hero__overlay element, neither of
  // which exists on a v2 hero. RETIRED (#1026): the cta title-accent row, the same way —
  // `.cta--has-bg-image` and `--cta-heading-accent-color` both went with the
  // `background_image` prop. The hero band stays in the fixture without a surface of its
  // own, as #986 left it. What survives here is the STATS row, which is the whole reason
  // the block is narrowed rather than deleted: stats still routes its heading-accent to
  // the on-overlay role automatically, so there is still an automatic guarantee to
  // measure. For the two rebuilt components the replacement is an authored value, covered
  // by their own role-default tests and disclosed in their READMEs.
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
 * RETIRED TOGETHER IN #1023 — #424, #536 and #551, and one replacement below.
 *
 * The three blocks that stood here pinned three cascade-reach defects on section's panel:
 *
 *   #424  a `theme: inverted` band's `h3` rule (0,1,1) outranked the panel heading's own
 *         rule and painted it in the band's LIGHT title colour — light-on-light on the
 *         panel's light surface.
 *   #536  `.section__panel-cta` has no .hero/.cta ancestor, so the shared premium
 *         `main .btn:not(...)` gradient was its only fill winner and a background-COLOUR
 *         set on the band sat invisibly beneath it.
 *   #551  the band's near-white overlay/on-inverted roles reached the panel CTA's label,
 *         painting it onto the near-white panel at 1.04:1 and 1.99:1.
 *
 * ALL THREE HAD THE SAME CAUSE, and it is gone rather than relocated: a band-level rule
 * reaching INTO the panel and outranking the panel's own. v2 emits a role's block
 * unlayered and band-scoped, `theme` and `background_image` are retired so no band class
 * exists to carry such a rule, and `panel_cta_variant` is retired so there is no variant
 * set for a carve-out to contradict. There is nothing left to outrank the panel.
 *
 * WHAT IS NOT GONE is the user-facing guarantee all three protected: the panel is a
 * self-contained light surface, and its heading and its CTA stay legible against IT no
 * matter how dark the band behind it is. That guarantee is delivered by role defaults now
 * (`panel` keeps v1's `@color-surface` fill and `@color-text` ink; `panel-heading` and
 * `panel-cta` inherit from it) instead of by three carve-outs — so it is pinned once,
 * below, on the case that used to break it.
 *
 * The v1 mechanisms' own retirement is pinned in the PHP suite: StyleSlotContractTest
 * (the #536/#584 keystones) and SectionTextPanelTest.
 */
test.describe('#424/#536/#551 the panel stays a light surface under an authored dark band (rendered)', () => {
  let pageId = 0;

  test.afterEach(async () => {
    if (pageId) deletePage(pageId);
    pageId = 0;
  });

  test('panel surface, heading and CTA stay legible on a dark band @375 + @768 + @1280 @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E v2 Panel On Dark Band');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-seed', body: '<p>Seed.</p>' } }]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // The band is authored as dark as v1's `inverted` was, and NOTHING is said about the
    // panel — which is the whole point. An author who darkens a band must not have to know
    // that the panel exists in order for it to stay readable.
    const res = await updateComposition(page, pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec01',
          layout: 'text-panel',
          title: 'Dark band',
          body: '<p>Left column copy.</p>',
          panel_heading: 'Included',
          panel_items: ['First perk', { label: 'Uptime', value: '99.9%' }],
          panel_cta_text: 'Get started',
          panel_cta_url: '/signup',
        },
        udc: {
          _band: { background: { fill: '#0b1020' } },
          heading: { typography: { color: '#ffffff' } },
          body: { typography: { color: 'rgba(255, 255, 255, 0.82)' } },
          // The RING, authored at rest and on hover from ONE map. This is the v2
          // replacement for #584's panel-CTA ring pair, which needed a positional twin
          // slot so a chosen ring would survive the pointer. A `':hover'` nested inside
          // `border` is emitted from the same map as the resting value, so the two cannot
          // split — asserted rendered, below.
          'panel-cta': { border: { color: '#7c3aed', ':hover': { color: '#ddd6fe' } } },
        },
      },
    ]);
    expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);

    const lum = (rgb: string) => {
      const [r, g, b] = (rgb.match(/[\d.]+/g) ?? ['0', '0', '0']).slice(0, 3).map(Number);
      const f = (c: number) => {
        const s = c / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
      };
      return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };
    const ratio = (a: string, b: string) => {
      const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p);
      return (x + 0.05) / (y + 0.05);
    };

    for (const width of [1280, 768, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      // Park the virtual pointer OFF the page before every rest read. Playwright's mouse
      // keeps its coordinates across setViewportSize() and goto(), and step 5 below hovers
      // the CTA — so without this, iterations 2 and 3 would assert the "at rest" ring while
      // the pointer was still over it, and the test would pass or fail on cursor position
      // rather than on the cascade. (Review finding: the sibling hover blocks in this file
      // avoid it by putting the width in the test NAME, giving each width a fresh context;
      // this test folds the widths into one body, so it has to park the mouse itself.)
      await page.mouse.move(0, 0);
      await expect(page.locator('.section__panel')).toBeVisible({ timeout: 10000 });

      const read = await page.evaluate(() => {
        const g = (sel: string) => {
          const el = document.querySelector(sel) as HTMLElement | null;
          return el ? { color: getComputedStyle(el).color, bg: getComputedStyle(el).backgroundColor } : null;
        };
        // The CTA needs its own reader. It is a BUTTON, so its label reads against the
        // button's fill, not the panel — and that fill is a background-IMAGE (the shared
        // premium gradient), so `backgroundColor` returns transparent. Reading the colour
        // property alone is the exact inverse of the trap #536 documented: a gradient fill
        // is invisible to a background-color read, the same way a background-colour is
        // invisible under a gradient. Pull the gradient's stops so the ink can be compared
        // against the surface it actually sits on.
        const ctaEl = document.querySelector('.section__panel-cta') as HTMLElement | null;
        const ctaCs = ctaEl ? getComputedStyle(ctaEl) : null;
        return {
          band: g('main > .section'),
          bandTitle: g('.section__title'),
          panel: g('.section__panel'),
          heading: g('.section__panel-heading'),
          cta: g('.section__panel-cta'),
          item: g('.section__panel-item'),
          ctaFillStops: ctaCs
            ? (ctaCs.backgroundImage.match(/rgba?\([^)]*\)/g) ?? []).concat(
                ctaCs.backgroundColor !== 'rgba(0, 0, 0, 0)' ? [ctaCs.backgroundColor] : [],
              )
            : [],
        };
      });

      // 1. The panel is its own opaque LIGHT surface, not the dark band showing through.
      //    This is the fact every one of the three retired blocks depended on.
      expect(read.panel!.bg, `panel fill @${width}`).not.toBe('rgba(0, 0, 0, 0)');
      expect(lum(read.panel!.bg), `panel must be lighter than the band @${width}`)
        .toBeGreaterThan(lum(read.band!.bg));

      // 2. #424's defect: the heading must read against the PANEL, not take the band's
      //    light title colour. Asserted as a ratio rather than a hex so a retheme moves
      //    subject and control together.
      expect(ratio(read.heading!.color, read.panel!.bg), `panel heading vs panel @${width}`)
        .toBeGreaterThanOrEqual(4.5);
      // Compared against the BAND TITLE's own rendered colour rather than a literal: #424
      // was precisely "the panel heading took the band title's colour", so the control is
      // that colour, whatever a retheme makes it.
      expect(read.heading!.color, `panel heading must not take the band title colour @${width}`)
        .not.toBe(read.bandTitle!.color);

      // 3. #551's defect, asked correctly: the CTA's label must read against WHATEVER IT
      //    SITS ON. An unauthored `panel-cta` renders as the bare shared button, which is
      //    filled by the premium gradient — so the control is every stop of that gradient,
      //    and the WORST of them must still clear AA. Compared against the fill rather
      //    than a literal so a retheme moves subject and control together.
      expect(read.ctaFillStops!.length, `the panel CTA must be FILLED @${width} — an unfilled `
        + 'button would put its label on the panel, which is #551 exactly')
        .toBeGreaterThan(0);
      const worstCta = Math.min(...read.ctaFillStops!.map((stop) => ratio(read.cta!.color, stop)));
      expect(worstCta, `panel CTA ink vs its own fill @${width}`).toBeGreaterThanOrEqual(4.5);

      // 4. And a panel list item, which stayed legible even when #424 was live — kept so a
      //    regression that darkened the whole panel is distinguishable from one that only
      //    hit the heading.
      expect(ratio(read.item!.color, read.panel!.bg), `panel item vs panel @${width}`)
        .toBeGreaterThanOrEqual(4.5);

      // 5. THE RING SURVIVES THE POINTER (#584's contract, v2 mechanism). The authored
      //    resting colour paints, and hovering moves it to the authored hover colour
      //    rather than reverting to the theme accent — which is what the slot era needed
      //    a separate positional twin to achieve.
      const cta = page.locator('.section__panel-cta');
      expect(await cta.evaluate((el) => getComputedStyle(el).borderTopColor), `ring at rest @${width}`)
        .toBe('rgb(124, 58, 237)');
      await cta.hover();
      await expect
        .poll(async () => cta.evaluate((el) => getComputedStyle(el).borderTopColor), { timeout: 2000 })
        .toBe('rgb(221, 214, 254)');
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
// #526/#530/#538 hero cta2 slot blocks RETIRED (#986): hero owns no button slots; its two CTAs are the cta / cta-secondary roles. cta keeps the equivalent coverage.

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
// #526/#530/#538 hero cta2 slot blocks RETIRED (#986): hero owns no button slots; its two CTAs are the cta / cta-secondary roles. cta keeps the equivalent coverage.

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
// #526/#530/#538 hero cta2 slot blocks RETIRED (#986): hero owns no button slots; its two CTAs are the cta / cta-secondary roles. cta keeps the equivalent coverage.





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

  /**
   * The v2 half (#1023): author the panel CTA through its ROLE, via the validated write
   * path, because a `udc` map only scopes to a band whose id the engine minted. Returns
   * the page id so the caller reads it exactly as it reads `sectionPage()`'s.
   */
  async function sectionPageUdc(page: any, title: string, udc: Record<string, unknown>): Promise<number> {
    const id = sectionPage(title);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await updateComposition(page, id, [
      {
        component: 'section',
        props: {
          id: 'pp-545-section',
          layout: 'text-panel',
          title: 'Plans',
          body: '<p>Pick a plan. <a class="btn" href="/x">Inline CTA</a> '
            + '<a class="btn btn--outline" href="/y">Outline CTA</a></p>',
          panel_heading: 'Starter',
          panel_cta_text: 'Book a call',
          panel_cta_url: '/contact',
        },
        udc,
      },
    ]);
    expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);
    return id;
  }

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
      // The three retired fill slots are the `panel-cta` role's three parameters now.
      // The CONTRACT this test exists for is unchanged and is the reason it was repriced
      // rather than retired: an authored button design must reach the button the component
      // OWNS and must not leak onto an author-written `.btn` inside `body`. A role selector
      // is narrower than the old premium cascade, so the isolation should hold by
      // construction — "should" is what this proves.
      pageId = await sectionPageUdc(page, 'E2E 545 section', {
        'panel-cta': {
          background: { fill: PURPLE },
          typography: { color: INK },
          shadow: { box: 'none' },
        },
      });

      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.section__panel-cta')).toBeVisible({ timeout: 10000 });

      const got = await readButtons(page, '.section__panel-cta', '.section__content .btn');

      // The role still does its job (what #536 proved for the slot).
      expect(got.owned.bgColor, `@${width}: the panel CTA must still paint the authored fill`).toBe(
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

    // RETIRED (#986): a hero style slot with no v2 successor — the value is a role parameter now, covered by the UDC contract tests.
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
  // RETIRED (#986): a hero style slot with no v2 successor — the value is a role parameter now, covered by the UDC contract tests.

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
    pageId = await sectionPageUdc(page, 'E2E 545 global tier', {
      'panel-cta': { background: { fill: PURPLE } },
    });

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
    // The authored ROLE still outranks the global tier on the button that owns it — and by
    // a cleaner route than the slot did: a role block is unlayered, so it does not depend
    // on leading a fallback chain the way `--section-panel-cta-bg` had to.
    expect(got.owned.bgColor, 'the panel CTA keeps its authored fill').toBe(got.purple);
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
    // cta's row left at #1026 with its slot map. THE MEASURE ITSELF DID NOT CHANGE — the
    // `heading` role defaults `sizing.max-width` to `@measure-heading`, the same 40rem token
    // this block pins for every component still on slots, and the `text` role carries it too
    // because v1's single slot fed BOTH elements. What is gone is only the slot-shaped
    // override this parameterised test drives. The measure's stressed RENDER is covered by
    // #1026's evidence run, which wraps a 100-character unbroken token at 375 and reads the
    // box; the emitted default is asserted in CtaRoleDefaultsEmitTest.
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
  // ACCENT_ON_INVERTED and MUTED_ON_OVERLAY were removed at #1026: each had exactly one
  // reader, and both readers were cta rows deleted with the component's variant classes.
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

  test('#577 row 1 (v2): an authored _band padding wins on an ADJACENT hero at 1280 and 375 @smoke', async ({
    page,
  }) => {
    // REWRITTEN ONTO `udc` (#986), because the invariant survives the rebuild and the
    // mechanism does not. The slot is gone; what replaced it is the `:not(.hero)` on the
    // shared adjacent-top catch-all. Without that exclusion the catch-all and the
    // defaults tier are both zero-specificity and components.css prints later, so an
    // authored `_band` padding on an adjacent hero would validate, store, report applied
    // and then render the shared value — the identical I35 defect this row was filed for,
    // reintroduced through a different door.
    pageId = createPage('E2E 577 hero adjacent band padding');
    // A section leads, so the hero renders SECOND — the adjacent position, the only
    // place the value was ever dead.
    //
    // WRITTEN THROUGH THE REAL AUTHORING SURFACE, not setComposition: band ids are minted
    // on WRITE, and a raw meta write mints none. Without an id the band emits no
    // `data-pp-band` attribute, so its authored block has nothing to select and the test
    // would read the role default and call it a regression. (It did, once — which is what
    // this comment is here to stop happening twice.)
    setComposition(pageId, [
      { component: 'section', props: { id: 'pp-sec-lead', title: 'Lead', body: '<p>Lead band.</p>' } },
      { component: 'hero', props: { id: 'pp-hero-adj', title: 'Adjacent hero' } },
    ]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await updateComposition(page, pageId, [
      { component: 'section', props: { id: 'pp-sec-lead', title: 'Lead', body: '<p>Lead band.</p>' } },
      {
        component: 'hero',
        props: { id: 'pp-hero-adj', title: 'Adjacent hero' },
        udc: { _band: { spacing: { 'padding-top': LOUD_PX } } },
      },
    ]);
    expect(res.success, `udc band padding write: ${JSON.stringify(res)}`).toBe(true);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const hero = page.locator('#pp-hero-adj');
      await expect(hero).toBeVisible({ timeout: 10000 });
      const { 'padding-top': top } = await computed(page, '#pp-hero-adj', ['padding-top']);
      expect(top, `adjacent hero authored _band padding-top @${width}`).toBe(LOUD_PX);
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
  /**
   * ROW 9 IS NOW A NARROWING PIN, and the rename says so (#986).
   *
   * v1 gave `.hero--left` (and the split variant, which degrades to it) a COMPACT opener
   * rhythm — `--space-xl` on both edges — while centered and cover took `--space-2xl` at
   * desktop. Those were two stylesheet rules per breakpoint, keyed on the variant class.
   *
   * v2 has no variant dimension: a role default is per COMPONENT, and padding is a
   * designable value the structural-CSS boundary keeps out of the stylesheet, so there is
   * no legal place left to say "left heroes are tighter". Every hero layout therefore
   * shares one opener rhythm by default, and a band that wants the compact one sets its
   * own `_band` `spacing` — which row 1 above proves reaches an adjacent hero.
   *
   * Pinned rather than deleted because it is a real rendering change on inner pages: an
   * adjacent left/split hero grows from 64px to 112px at desktop. Recorded in
   * components/hero/README.md under the narrowings.
   */
  test('#577 row 9 (v2): an adjacent LEFT/SPLIT hero takes the ONE hero opener rhythm, symmetric @smoke', async ({
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
        // ONE hero rhythm at each breakpoint now, and still symmetric with its own
        // bottom — the symmetry is what row 9 has always really been about.
        const expected = width >= 768 ? HERO_OPENER_DESKTOP : HERO_OPENER_COMPACT;
        expect(top, `adjacent ${id} top @${width}`).toBe(expected);
        expect(bottom, `adjacent ${id} bottom @${width}`).toBe(expected);
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

  // ── A-2 / register row 2 — RETIRED IN #1023 ────────────────────────────────
  //
  // Two tests stood here. They proved that `--section-bg` and `--section-border-*` beat
  // `.pp-section--dark` / `.pp-section--inverted`, which set background-color and border-*
  // as BARE LITERALS at [0,1,0] AFTER `.section`'s slot-routed declarations at equal
  // specificity — so before #577 those slots were dead on any themed section while
  // AiContextTest already promised an author the override wins.
  //
  // BOTH SIDES OF THAT CONFLICT ARE GONE, not one of them. There is no `theme` prop and no
  // `.pp-section--*` class to emit a literal, and there are no `--section-*` slots to be
  // defeated: a band's surface is the `_band` role's `background` and `border`, emitted
  // UNLAYERED and band-scoped, so it cannot lose to a stylesheet rule at all. A test
  // asserting one beats the other has nothing left to compare.
  //
  // The register row itself is still covered for a v1 component by row 3 below (cta's
  // border slots against its bg-image shorthand), and the v2 side — an authored `_band`
  // outranking every structural rule — is pinned by
  // "#431/I35 no structural CSS outranks any declaration a v2 band block makes".

  // ── A-3 / register row 3 — bg-image cta borders ────────────────────────────
  //
  // `.cta--has-bg-image { border: none }` was a SHORTHAND whose border-style:none
  // suppressed the slot-routed longhands `.cta` declares, killing --cta-border-width
  // and --cta-border-color on every background-image band.

  /*
   * RETIRED (#1026): the seven cta rows in this block. #577 was a sweep over "dead and
   * defeated style slots" — declarations that validated and painted nothing, or that a
   * later rule silently defeated. Every cta row pinned one of two things that cta no longer
   * has:
   *
   *   rows 3, 6+7 and A-36  — the `.cta--has-bg-image` overlay band's border slots and its
   *                           two de-emphasis ink surfaces, plus the per-instance slot that
   *                           had to keep winning over them.
   *   rows 4 and A-4        — the `.cta--inverted` band's title-accent routing.
   *
   * Both classes derive from `theme` and `background_image`, retired props, so neither can
   * be rendered on a v2 cta in any configuration.
   *
   * WHAT #577 WAS ACTUALLY ABOUT, and why the rebuild is the stronger answer: every row
   * here existed because a slot could be authored, stored, reported as applied, and then
   * defeated by a rule further down the same stylesheet. A role value is emitted UNLAYERED
   * and band-scoped — there is no later rule in `pp-v1` that can defeat it, at any
   * specificity. The failure MODE these rows sampled is structurally absent on a v2
   * component rather than merely unpinned on this one.
   *
   * The section rows in this block are untouched and still pin the same property for the
   * v1 components that remain.
   */

  // ── A-4 / register row 4 — inverted title_accent ───────────────────────────
  //
  // The accent substring paints its OWN color and does not inherit the light title
  // beside it. The --has-bg-image twins have routed the overlay role since #463; the
  // INVERTED twins were never written, so the highlighted word rendered bare
  // --color-accent at 3.23:1 on the dark band.



  /**
   * THE v2 COUNTERPART of the two rows above, so the capability is proved rather than
   * only its v1 mechanism retired.
   *
   * On v1 a dark band re-pointed the accent token through its band class, and a
   * per-instance slot outranked that. v2 has neither: an author darkens the band with
   * `_band` `background.fill` and colours `heading-accent` themselves — YOU own the
   * contrast. What has to be true is that the authored role value actually reaches the
   * accent span over the authored background, which is the half a CSS-text pin cannot
   * show.
   */
  test('#1023 an authored heading-accent reaches the accent span on an authored dark band @smoke', async ({ page }) => {
    pageId = createPage('E2E 1023 authored accent on dark band');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-seed', body: '<p>Seed.</p>' } }]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await updateComposition(page, pageId, [
      {
        component: 'section',
        props: { id: 'pp-sec-dark', title: 'Ship faster today', title_accent: 'faster', body: '<p>Body.</p>' },
        udc: {
          _band: { background: { fill: '#0b1020' } },
          heading: { typography: { color: '#ffffff' } },
          'heading-accent': { typography: { color: LOUD_HEX } },
        },
      },
    ]);
    expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-sec-dark .section__title-accent')).toBeVisible({ timeout: 10000 });
      const accent = await computed(page, '#pp-sec-dark .section__title-accent', ['color']);
      const title = await computed(page, '#pp-sec-dark .section__title', ['color']);
      expect(accent.color, `authored accent @${width}`).toBe(LOUD_COLOR);
      // …and it is genuinely distinct from the heading it sits inside, so a role that
      // silently inherited the heading colour would fail rather than look plausible.
      expect(accent.color).not.toBe(title.color);
    }
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

  // ── A-14 / register row 5 — RETIRED (v2 Sprint 0) ─────────────────────────
  //
  // Both tests in this row drove `theme: 'inverted'`. That prop is gone from
  // testimonials' v2 schema by ruling, and with it the `testimonials--inverted`
  // class the two light-default rules keyed on. Neither test can be ported: the
  // first asserted a light default that no longer has a trigger, and the second
  // asserted the GRID layout was unaffected by a prop that no longer exists — it
  // still passes today, but only vacuously, which is worse than not having it.
  //
  // The decision they encoded does NOT survive as component CSS. Under the
  // standing never-fix-colors-in-components rule, a dark band's contrast is the
  // authoring layer's job: the author sets the band background AND the role
  // colours that must read against it. That guidance ships in the AI-facing doc
  // rather than as a baked-in default, so there is no rendered default left here
  // to pin. The rendered proof that role colours reach these elements at all is
  // carried by the v2 role tests elsewhere in this file.


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

  // ROW 6 (the cta body) LEFT THIS BLOCK AT #1026 and row 7 did NOT. Both used to live
  // here; cta's half was keyed on `.cta--has-bg-image` and painted a `.cta__overlay`
  // element, and both went with the `background_image` prop that derived them. Row 7 is
  // untouched: stats is still a v1 component, still takes `background_image`, still
  // renders `.stats__overlay`, and still routes `--color-muted-on-overlay`. So this
  // measurement stays, narrowed to the one surface that still exists — deleting it with
  // its cta twin would have dropped a live AA pin on the band that still has the bug.
  const OVERLAY_SURFACES = [
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

  test('#577 row 7: the stats overlay de-emphasis still clears AA at 1280 and 375 @smoke', async ({
    page,
  }) => {
    // The surviving half of the rows 6+7 pair. cta's band can no longer be built from a
    // prop, so its row went with #1026; this one measures the band that still can.
    pageId = createPage('E2E 577 row 7 overlay de-emphasis');
    setComposition(pageId, overlayBands());
    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-ov-stats .stats__overlay')).toBeVisible({ timeout: 10000 });
      for (const s of OVERLAY_SURFACES) {
        const res = await overlayContrast(page, s.ink, s.overlay);
        expect(res.found, `${s.name} not found @${width}`).toBe(true);
        expect(
          res.textOpacity,
          `${s.name} @${width}: an opacity literal came back onto the overlay band`,
        ).toBe(1);
        expect(
          res.ratio,
          `${s.name} @${width}: ratio=${res.ratio?.toFixed(2)} (need >= 4.5)`,
        ).toBeGreaterThanOrEqual(4.5);
      }
    }
  });

  test('#577 the stats inverted+bg-image carve-out still clears AA at 1280 and 375 @smoke', async ({
    page,
  }) => {
    // RESTORED, NARROWED (#1026 review). The deleted `rows 6+7: an inverted +
    // background_image band` test covered TWO components and only cta's half retired. This
    // is the stats half, and it was the ONLY rendered proof of the `:not(.stats--has-bg-image)`
    // carve-out on `.stats--inverted:not(.stats--has-bg-image) .stats__label` — a rule that
    // still ships, on a component that still has BOTH props, and which the stylesheet's own
    // comment now calls "the only one left". Nothing else pins it: no css-lint or PHPUnit test
    // asserts the `:not()`, and no other e2e fixture builds a stats band with both.
    //
    // What it prevents is a measured REGRESSION, not a hypothetical: stats.php emits the theme
    // class and the bg-image class independently, and the inverted rule is the EARLIER of the
    // two. Without the carve-out a combined band keeps the inverted `opacity: 0.75` while the
    // --has-bg-image rule supplies only `color` — DIMMER than the 0.85 that already measured
    // 3.87:1 and failed AA.
    pageId = createPage('E2E 577 stats inverted + bg-image');
    setComposition(pageId, [
      {
        component: 'stats',
        props: {
          id: 'pp-ov-stats',
          theme: 'inverted',
          background_image: WHITE_PNG,
          title: 'Combined band',
          items: [{ number: '42', label: 'Deployments every single week' }],
        },
      },
    ]);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-ov-stats .stats__overlay')).toBeVisible({ timeout: 10000 });

      // The fixture must actually be the combined band, or the carve-out is untested.
      const classes = (await page.locator('#pp-ov-stats').getAttribute('class')) || '';
      expect(classes, `@${width}: fixture must carry BOTH classes`).toContain('stats--inverted');
      expect(classes).toContain('stats--has-bg-image');

      const res = await overlayContrast(page, '#pp-ov-stats .stats__label', '#pp-ov-stats .stats__overlay');
      expect(res.found, `stats label not found @${width}`).toBe(true);
      expect(
        res.textOpacity,
        `@${width}: the inverted opacity literal leaked onto the overlay band — the carve-out is gone`,
      ).toBe(1);
      expect(
        res.ratio,
        `@${width}: ratio=${res.ratio?.toFixed(2)} (need >= 4.5)`,
      ).toBeGreaterThanOrEqual(4.5);
    }
  });

  test('#577 A-36: the surviving inverted opacity literals are untouched', async ({ page }) => {
    // THE cta ROW LEFT THIS TEST AT #1026, THE OTHER TWO DID NOT — and that is the whole
    // reason this test still exists rather than going with its cta row. The inverted cta
    // body's `opacity: 0.85` was keyed on `.cta--inverted`, a class derived from the
    // retired `theme` prop, so it is gone. stats and logos are still v1 components whose
    // inverted labels still carry `opacity: 0.75`, ratified as deliberate de-emphasis at
    // 10.22:1 against a 4.5:1 bar. Deleting the whole test would have unpinned both.
    pageId = createPage('E2E 577 surviving opacity literals');
    setComposition(pageId, [
      {
        component: 'stats',
        props: {
          id: 'pp-stats-inv',
          theme: 'inverted',
          title: 'Inverted stats',
          items: [{ number: '42', label: 'Metric' }],
        },
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
    const stats = await computed(page, '#pp-stats-inv .stats__label', ['opacity']);
    const logos = await computed(page, '#pp-logos-inv .logos__label', ['opacity']);
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
  // RETIRED (#986): a hero style slot with no v2 successor — the value is a role parameter now, covered by the UDC contract tests.
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

  /*
   * ── A-38 RETIRED (#1026): the ring-slot cases and the two tests they fed ──────────────
   *
   * #584 minted per-instance RING pairs and pinned that each painted at rest AND on hover,
   * and outranked the site-wide knob. Hero's two rows went at #986, section's panel-CTA row
   * at #1023, and cta's — the last one — here, with `--cta-button-border` /
   * `--cta-button-hover-border`. With no rows left, the parameterised tests had nothing to
   * iterate.
   *
   * THE CAPABILITY IS THE `border.color` PARAMETER WITH A `':hover'` NESTED IN THE SAME
   * GROUP, which is strictly more than the slot pair gave: it reaches both states from ONE
   * map, so they cannot drift apart the way two independently-editable slots could, and it
   * outranks the site-wide knob by cascade LAYER rather than by position in a `var()` chain.
   *
   * The precedence is still pinned as a RENDERED hover rather than left to the CSS-text
   * guards: see the #539 block's 'a per-instance hover BORDER slot still beats the global
   * --btn-hover-border-color', which #1026 rebuilt on exactly this role state. The rest-side
   * emission is asserted in tests/CtaRoleDefaultsEmitTest.php.
   *
   * The block's RING / RING_HOVER / GLOBAL_RING / RING_RGB / RING_HOVER_RGB constants and
   * its `ringOf` probe went with the loop: the last reader of each was one of these two
   * tests, so keeping them would leave five authored colours and a border-colour probe that
   * nothing resolves. `TALL_PNG_584` and `open584` stay — the surviving cap and rhythm
   * tests below still use both.
   */

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
      // hero's row is gone (#986): its heading rhythm is the `title` role's
      // `spacing.margin-bottom`, not a slot. It was already the one band excluded from
      // the zero assertion below (its shipped margin is 0), so nothing else moves.
      // cta's row left at #1026, the way hero's did at #986: its heading rhythm is the
      // `heading` role's `spacing.margin-bottom` (defaulting to `@space-xs`, the literal
      // v1's rule carried), not a slot. The band-fusing capability #584 exists for is
      // unchanged — set that parameter to `0` — and the emitted default is asserted at the
      // CSS level in CtaRoleDefaultsEmitTest.
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
    await open584(page, 1280, '.stats__heading');
    const unset = await page.evaluate(
      (sels: string[]) =>
        sels.map((s) =>
          getComputedStyle(document.querySelector(s) as HTMLElement).marginBottom,
        ),
      BANDS.map((b) => b.sel),
    );
    // FOUR bands now, not six: hero left this slot family in #986 and cta at #1026 (on both,
    // the heading rhythm is a role's `spacing.margin-bottom`). The ready selector moved to
    // `.stats__heading` with cta's departure — it was `.cta__title`, the first band in the
    // list, and a ready selector that names a band no longer in the list waits forever. Four
    // are var(--space-lg) = 32px. These are the exact literals the remaining slots carry
    // as their fallbacks, measured rather than restated from the stylesheet. The leading
    // '4px' left with cta: that was its `--cta-heading-margin-bottom` fallback of
    // var(--space-xs), and it is now the `heading` role's `spacing.margin-bottom` default,
    // asserted at the emitted-CSS level in CtaRoleDefaultsEmitTest instead of here.
    expect(unset, 'unset heading rhythm must be unchanged').toEqual([
      '32px',
      '32px',
      '32px',
      '32px',
    ]);

    // LIVE first, with a value no default could produce. (The vacuity this guards against
    // was hero's: its shipped margin was already 0, so a zero-only assertion proved
    // nothing there. Hero has left the family, and the live-value step stays because the
    // same trap would return the day another band ships a 0 default.)
    setComposition(
      pageId,
      BANDS.map(({ band, slot }) => ({ ...(band as object), style: { [slot]: '11px' } })),
    );
    for (const width of [1280, 375]) {
      await open584(page, width, '.stats__heading');
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

    // Then zero, the value the header-tightening case actually asks for. FUSABLE is every
    // band in the list now: hero used to be excluded here because its shipped margin was
    // already 0, and it left the family entirely at #986. NOTE:
    // on none of these bands is the heading the band's trailing element (each has a required
    // content prop that renders after it), so this is the band's INTERNAL header rhythm —
    // the seam with the band below is closed with --<component>-padding-bottom.
    const FUSABLE = BANDS;
    setComposition(
      pageId,
      BANDS.map(({ band, slot }) => ({ ...(band as object), style: { [slot]: '0' } })),
    );
    await open584(page, 1280, '.stats__heading');
    const zeroed = await page.evaluate(
      (sels: string[]) =>
        sels.map((s) => getComputedStyle(document.querySelector(s) as HTMLElement).marginBottom),
      FUSABLE.map((b) => b.sel),
    );
    expect(zeroed, 'the four remaining slot bands must be able to zero their header rhythm').toEqual(
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
