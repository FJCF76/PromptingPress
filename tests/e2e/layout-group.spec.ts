/**
 * tests/e2e/layout-group.spec.ts — the Layout group as RENDERED (#1084).
 *
 * The PHP suite pins what the engine emits. This pins what a browser does with it,
 * and for this group the two are further apart than usual, because the whole design
 * is a CASCADE claim: authored values sit unlayered above a stylesheet in
 * `@layer pp-v1`, and the group deliberately does NOT move that stylesheet.
 *
 * Four claims, none of which a unit test can make:
 *   1. an authored value OUTRANKS a variant-scoped structural rule (the capability);
 *   2. with nothing authored, that same rule paints exactly as before (the D5
 *      unauthored-case lesson — an overlay that changes an untouched band is not an
 *      overlay);
 *   3. a column COUNT renders as real tracks at every viewport the author set it
 *      for, including the phone, where `.section__grid` is `display: flex` and the
 *      value would otherwise paint nothing;
 *   4. a responsive count still works after minting, where the emitted CSS is
 *      `repeat(var(--pp-…), minmax(0, 1fr))`.
 *
 * STRESSED, per rule 14.2: the fixtures carry a 46-character unbroken token and
 * long copy, because a track list's failure mode is horizontal overflow, not a
 * wrong number — `repeat(N, 1fr)` would pass a track-count assertion and scroll the
 * page sideways (the #1043/#1067 class). Every viewport asserts no horizontal
 * scroll, and the screenshots are kept as the artifact a human looks at.
 */
import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const SHOTS = path.resolve(process.cwd(), 'test-results/layout-group');

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

/**
 * The v2 authoring path, mirroring style-render.spec.ts's helper of the same name.
 * A raw meta write would skip write-time normalization — and normalization is the
 * whole subject of the minting test below, whose claim is that the value is
 * REWRITTEN into band tokens and still renders. Seeding it by hand would assert
 * against a shape no author can produce.
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

/** The stress copy: one unbroken 46-character token beside ordinary prose. */
const STRESS =
  'Onboarding for Kundenservicebereitschaftsplanungsteams runs in six weeks, with a review after each milestone.';

const VIEWPORTS = [
  { label: 'phone', width: 375, height: 900 },
  { label: 'tablet', width: 768, height: 900 },
  { label: 'desktop', width: 1280, height: 900 },
];

test.describe('The Layout group, rendered', () => {
  let pageId = 0;

  test.beforeAll(() => {
    fs.mkdirSync(SHOTS, { recursive: true });
  });

  test.afterEach(() => {
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  test('@smoke an authored layout value beats the variant rule, and an unauthored band keeps it', async ({
    page,
  }) => {
    pageId = createPage('E2E layout group');
    // RAW META IS DELIBERATE HERE, and the test below uses the real write path for
    // the opposite reason. This one is a RENDER-boundary fixture: its claims are
    // about the cascade, and it needs stable band ids to address four bands in one
    // page (the write path mints `pp-<hex8>` over any id it does not consider
    // usable, which the minting test relies on). Rule 14.1's authoring-path mandate
    // is met in tests/UdcLayoutGroupTest.php, which writes the group through
    // `create_page` validation.
    setComposition(pageId, [
      // 1. AUTHORED: a four-across process band — #905's shape, on the component
      //    whose own CSS is flex below 768px and a two-column grid above it.
      {
        component: 'section',
        id: 'pp-layout-authored',
        props: {
          id: 'pp-layout-authored',
          title: 'Authored four-across',
          body: STRESS,
          layout: 'image-right',
          image_url: 'https://example.test/none.png',
        },
        udc: {
          columns: { layout: { columns: { d: 4, p: 2 }, align: 'start' } },
        },
      },
      // 2. CONTROL: the identical band with NO udc map at all. Claim 2 lives here.
      {
        component: 'section',
        id: 'pp-layout-control',
        props: {
          id: 'pp-layout-control',
          title: 'Unauthored control',
          body: STRESS,
          layout: 'image-right',
          image_url: 'https://example.test/none.png',
        },
      },
      // 2b. #658 ITSELF: the panel column placing itself, beside an unauthored
      //     twin. `align-self` is the reason the parameter went to `sizing`, so its
      //     cascade claim gets the same browser proof the container params get.
      {
        component: 'section',
        id: 'pp-layout-panel',
        props: {
          id: 'pp-layout-panel',
          title: 'Panel authored',
          body: STRESS,
          layout: 'text-panel',
          panel_heading: 'What you get',
          panel_body: 'A short panel that is shorter than the prose beside it.',
        },
        udc: { panel: { sizing: { 'align-self': 'center' } } },
      },
      {
        component: 'section',
        id: 'pp-layout-panel-control',
        props: {
          id: 'pp-layout-panel-control',
          title: 'Panel control',
          body: STRESS,
          layout: 'text-panel',
          panel_heading: 'What you get',
          panel_body: 'A short panel that is shorter than the prose beside it.',
        },
      },
      // 3. A cta whose `inline` variant sets flex-direction: row from 768px. The
      //    authored `orientation` must beat it — a variant-scoped rule in pp-v1
      //    against an unlayered authored value.
      {
        component: 'cta',
        id: 'pp-layout-cta',
        props: {
          id: 'pp-layout-cta',
          layout: 'inline',
          title: 'Ready when you are',
          button_text: 'Start',
          button_url: '/start',
        },
        udc: { inner: { layout: { orientation: 'column' } } },
      },
      // 4. The same cta, unauthored: its variant must still be a row at >=768px.
      {
        component: 'cta',
        id: 'pp-layout-cta-control',
        props: {
          id: 'pp-layout-cta-control',
          layout: 'inline',
          title: 'Control',
          button_text: 'Start',
          button_url: '/start',
        },
      },
    ]);

    const url = `/?page_id=${pageId}`;

    for (const vp of VIEWPORTS) {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.goto(url);

      const read = await page.evaluate(() => {
        const box = (sel: string) => {
          const el = document.querySelector(sel) as HTMLElement | null;
          if (!el) return null;
          const cs = getComputedStyle(el);
          return {
            display: cs.display,
            columns: cs.gridTemplateColumns,
            direction: cs.flexDirection,
            alignItems: cs.alignItems,
            alignSelf: cs.alignSelf,
          };
        };
        return {
          authored: box('[data-pp-band="pp-layout-authored"] .section__grid'),
          panel: box('[data-pp-band="pp-layout-panel"] .section__panel'),
          panelControl: box('[data-pp-band="pp-layout-panel-control"] .section__panel'),
          control: box('[data-pp-band="pp-layout-control"] .section__grid'),
          cta: box('[data-pp-band="pp-layout-cta"] .cta__inner'),
          ctaControl: box('[data-pp-band="pp-layout-cta-control"] .cta__inner'),
          docWidth: document.documentElement.scrollWidth,
          winWidth: window.innerWidth,
        };
      });

      // CLAIM 3 — the count renders as real tracks at EVERY viewport, phone
      // included, because the engine emitted `display: grid` with it.
      expect(read.authored, 'the authored band must be on the page').not.toBeNull();
      expect(read.authored!.display, `${vp.label}: authored columns must make the box a grid`).toBe('grid');
      const expectedTracks = vp.width < 768 ? 2 : 4;
      expect(
        read.authored!.columns.split(' ').length,
        `${vp.label}: expected ${expectedTracks} tracks from the authored count`,
      ).toBe(expectedTracks);
      expect(read.authored!.alignItems, `${vp.label}: authored align must win`).toBe('start');

      // CLAIM 2 — the unauthored control is untouched: flex column on the phone,
      // the stylesheet's own two-column grid from 768px.
      if (vp.width < 768) {
        expect(read.control!.display, `${vp.label}: an unauthored band must still stack`).toBe('flex');
        expect(read.control!.direction, `${vp.label}: an unauthored stack is a flex column`).toBe('column');
      } else {
        expect(read.control!.display, `${vp.label}: an unauthored band keeps the stylesheet grid`).toBe('grid');
        expect(read.control!.columns.split(' ').length, `${vp.label}: the shipped two-column track`).toBe(2);
      }

      // #658 — the authored panel places itself, and its unauthored twin does not.
      // The stylesheet gives `.section--text-panel .section__grid` align-items:
      // start from 768px, so the control's panel resolves to that and the authored
      // one must override it on its own box.
      expect(read.panel, 'the panel band must be on the page').not.toBeNull();
      expect(read.panel!.alignSelf, `${vp.label}: an authored align-self must win on the panel`).toBe('center');
      expect(
        read.panelControl!.alignSelf,
        `${vp.label}: the unauthored panel keeps whatever the stylesheet gives it`,
      ).not.toBe('center');

      // CLAIM 1 — the authored orientation beats `.cta--inline`'s row at >=768px,
      // where the variant rule is the one that would otherwise apply.
      expect(read.cta!.direction, `${vp.label}: authored orientation must beat the variant rule`).toBe('column');
      expect(
        read.ctaControl!.direction,
        `${vp.label}: the unauthored cta must keep its variant's own direction`,
      ).toBe(vp.width >= 768 ? 'row' : 'column');

      // STRESS — a track list must never produce a sideways page (#1043/#1067).
      expect(
        read.docWidth,
        `${vp.label}: the page scrolls sideways — a track is not shrinking to its column`,
      ).toBeLessThanOrEqual(read.winWidth + 1);

      await page.screenshot({
        path: path.join(SHOTS, `layout-group-${vp.width}.png`),
        fullPage: true,
      });
    }
  });

  test('@smoke a responsive column count survives minting and renders per tier', async ({ page }) => {
    pageId = createPage('E2E layout group minted');
    // Written through the REAL action so the value is MINTED exactly as an author's
    // would be: the emitted CSS is then `repeat(var(--pp-…), minmax(0, 1fr))`, which
    // is the form a unit test cannot prove a browser resolves.
    // The chat screen is where `ppAiChat` (and its execute nonce) is enqueued;
    // a bare /wp-admin/ has neither, which is how the first run of this test failed.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    const res = await updateComposition(page, pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-layout-mint',
          title: 'Minted',
          body: 'Copy.',
          layout: 'image-right',
          image_url: 'https://example.test/none.png',
        },
        udc: { columns: { layout: { columns: { d: 3, p: 1 } } } },
      },
    ]);
    expect(res.success, `udc write: ${JSON.stringify(res)}`).toBe(true);

    for (const vp of VIEWPORTS) {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.goto(`/?page_id=${pageId}`);
      const read = await page.evaluate(() => {
        // The band is addressed by ATTRIBUTE PRESENCE rather than by the id this
        // test asked for, because the write path owns band identity: an authored
        // `props.id` that is not a usable band id is replaced by a minted
        // `pp-<hex8>` (pp_udc_assign_band_ids). Asserting on a chosen id would test
        // the fixture rather than the rendering — this page carries exactly one band.
        const el = document.querySelector('[data-pp-band] .section__grid') as HTMLElement | null;
        if (!el) return null;
        const cs = getComputedStyle(el);
        const band = (el.closest('[data-pp-band]') as HTMLElement).dataset.ppBand;
        return { display: cs.display, columns: cs.gridTemplateColumns, band };
      });
      expect(read, 'the minted band must render').not.toBeNull();
      expect(read!.display).toBe('grid');
      expect(
        read!.columns.split(' ').length,
        `${vp.label}: a minted count must resolve inside repeat()`,
      ).toBe(vp.width < 768 ? 1 : 3);
    }
  });
});
