import { test } from '@playwright/test';
import { execSync } from 'child_process';
import fs from 'fs';

/**
 * #1066 PR2 V1 MEASUREMENT PROBE — NOT A PIN. Deleted before the PR.
 *
 * THE RULE THIS SERVES: a v2 role default is what v1 RENDERED, measured in Chromium at
 * 375/768/1280, never what the stylesheet DECLARED. Every serious defect in #1023 came
 * from skipping one of the fourfold conditions (ancestors / media scope / inherit-vs-
 * literal / explicit-inherit-is-a-declaration), and #1026 shipped a 1.016:1 heading by
 * porting a declaration instead of a render.
 *
 * TWO THINGS HERE ARE NOT ORDINARY READS:
 *
 * 1. THE #367 HEADING BOX. `.stats__heading` carries `margin-left/right: auto` plus a
 *    40rem cap inside a wider `.container`. The auto margins are what CENTRE THE BOX;
 *    without them the cap pins the box to the container's left edge and `text-align:
 *    center` only centres within it, leaving the heading left of page centre. A computed
 *    read of `margin-left` returns the USED value in px, not `auto`, so the box x/width
 *    is recorded too — that is the only way to prove the centring survives the port.
 *
 * 2. THE A-36 OPACITY BLEND. `.stats--inverted:not(.stats--has-bg-image) .stats__label`
 *    and `.logos--inverted .logos__label` both carry `opacity: 0.75`. `opacity` has NO
 *    home in the UDC vocabulary (7 groups, none declares it), so the port has to express
 *    the de-emphasis as a COLOUR. getComputedStyle reports colour and opacity separately
 *    and never their composite, so the rendered label is PIXEL-SAMPLED: that sampled
 *    value is the number the migration doc hands an author, and guessing it from
 *    arithmetic is exactly the "computed style cannot see paint" mistake #1046 recorded.
 */

const OUT = process.env.PP_OUT || '/home/wfroot-n5i9y/.claude/pp-orchestrator/evidence-1066-pr2/pr2-v1-measure.json';
const SHOTS = process.env.PP_SHOTS || '/home/wfroot-n5i9y/.claude/pp-orchestrator/evidence-1066-pr2/shots-v1';

function sh(cmd: string): string {
  return execSync(cmd, { cwd: process.cwd(), encoding: 'utf-8' }).trim();
}
function createPage(title: string): number {
  const id = parseInt(
    sh(`npx wp-env run cli wp post create --post_type=page --post_status=publish --post_author=1 --post_title="${title}" --porcelain`),
    10,
  );
  sh(`npx wp-env run cli wp post meta update ${id} _wp_page_template composition.php`);
  return id;
}
function setComposition(postId: number, composition: unknown[]): void {
  const json = JSON.stringify(composition).replace(/'/g, "'\\''");
  sh(`npx wp-env run cli wp post meta update ${postId} _pp_composition '${json}'`);
}

const STATS_ITEMS = [
  { number: '+30', label: 'Years in practice' },
  { number: '1,200', label: 'Matters resolved' },
  { number: '98%', label: 'Client retention' },
];
const LOGOS_ITEMS_PLAIN = [
  { image_url: '/wp-content/uploads/a.png', image_alt: 'A' },
  { image_url: '/wp-content/uploads/b.png', image_alt: 'B' },
];
const LOGOS_ITEMS_MIXED = [
  { image_url: '/wp-content/uploads/a.png', image_alt: 'A' },
  { image_url: '/wp-content/uploads/b.png', image_alt: 'B', label: 'Labelled tile' },
];

// The scenes. Each is one stored composition; the theme values are the ones being RETIRED,
// so they have to be measured before they go.
const SCENES: Array<{ key: string; composition: unknown[] }> = [
  { key: 'stats-default',  composition: [{ component: 'stats', props: { id: 'pp-s1', title: 'By the numbers', title_accent: 'numbers', items: STATS_ITEMS } }] },
  { key: 'stats-muted',    composition: [{ component: 'stats', props: { id: 'pp-s2', title: 'By the numbers', title_accent: 'numbers', theme: 'muted', items: STATS_ITEMS } }] },
  { key: 'stats-inverted', composition: [{ component: 'stats', props: { id: 'pp-s3', title: 'By the numbers', title_accent: 'numbers', theme: 'inverted', items: STATS_ITEMS } }] },
  { key: 'stats-bgimage',  composition: [{ component: 'stats', props: { id: 'pp-s4', title: 'By the numbers', title_accent: 'numbers', background_image: '/wp-content/uploads/bg.jpg', items: STATS_ITEMS } }] },
  // The #577 carve-out shape: inverted AND a background image on the same band.
  { key: 'stats-inverted-bgimage', composition: [{ component: 'stats', props: { id: 'pp-s5', title: 'By the numbers', title_accent: 'numbers', theme: 'inverted', background_image: '/wp-content/uploads/bg.jpg', items: STATS_ITEMS } }] },
  { key: 'stats-notitle',  composition: [{ component: 'stats', props: { id: 'pp-s6', items: STATS_ITEMS } }] },
  { key: 'logos-default',  composition: [{ component: 'logos', props: { id: 'pp-l1', title: 'Trusted by', items: LOGOS_ITEMS_PLAIN } }] },
  { key: 'logos-muted',    composition: [{ component: 'logos', props: { id: 'pp-l2', title: 'Trusted by', theme: 'muted', items: LOGOS_ITEMS_PLAIN } }] },
  { key: 'logos-inverted', composition: [{ component: 'logos', props: { id: 'pp-l3', title: 'Trusted by', theme: 'inverted', items: LOGOS_ITEMS_PLAIN } }] },
  { key: 'logos-mixed',    composition: [{ component: 'logos', props: { id: 'pp-l4', title: 'Trusted by', items: LOGOS_ITEMS_MIXED } }] },
];

const VIEWPORTS = [375, 768, 1280];

test('#1066 PR2 — measure v1 stats and logos as RENDERED', async ({ page }) => {
  test.setTimeout(30 * 60 * 1000);
  fs.mkdirSync(SHOTS, { recursive: true });
  const out: Record<string, unknown> = {};

  for (const scene of SCENES) {
    const id = createPage(`pr2-measure-${scene.key}`);
    setComposition(id, scene.composition);
    const perScene: Record<string, unknown> = {};

    try {
      for (const w of VIEWPORTS) {
        await page.setViewportSize({ width: w, height: 1100 });
        await page.goto(`/?page_id=${id}`, { waitUntil: 'load', timeout: 30000 });

        const read = await page.evaluate(() => {
          const want = [
            'padding-top', 'padding-bottom', 'background-color', 'background-image',
            'background-size', 'background-position', 'background-repeat',
            'max-width', 'margin-left', 'margin-right', 'border-radius',
            'border-top-width', 'border-top-style', 'border-top-color',
            'border-bottom-width', 'border-bottom-style', 'border-bottom-color',
            'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing',
            'color', 'text-align', 'margin-bottom', 'opacity',
            'display', 'flex-direction', 'flex-wrap', 'align-items', 'justify-content',
            'gap', 'row-gap', 'column-gap', 'min-width', 'max-height', 'width',
            'object-fit', 'position', 'inset-block-start', 'z-index', 'list-style-type',
            'padding-left', 'margin-top',
          ];
          const grab = (sel: string) => {
            const el = document.querySelector(sel);
            if (!el) return null;
            const cs = getComputedStyle(el);
            const o: Record<string, string> = {};
            for (const p of want) o[p] = cs.getPropertyValue(p);
            const b = el.getBoundingClientRect();
            o.__box = JSON.stringify({ x: Math.round(b.x), y: Math.round(b.y), w: Math.round(b.width), h: Math.round(b.height) });
            return o;
          };
          return {
            band:          grab('[data-pp-component="stats"], [data-pp-component="logos"]'),
            container:     grab('[data-pp-component="stats"] > .container, [data-pp-component="logos"] > .container'),
            overlay:       grab('.stats__overlay'),
            heading:       grab('.stats__heading, .logos__heading'),
            headingAccent: grab('.stats__heading-accent'),
            list:          grab('.stats__list, .logos__list'),
            item:          grab('.stats__item, .logos__item'),
            itemLabeled:   grab('.logos__item--labeled'),
            number:        grab('.stats__number'),
            label:         grab('.stats__label, .logos__label'),
            image:         grab('.logos__image'),
            imageLabeled:  grab('.logos__item--labeled .logos__image'),
            rootClasses:   (document.querySelector('[data-pp-component="stats"], [data-pp-component="logos"]') as HTMLElement | null)?.className ?? null,
          };
        });
        perScene[`w${w}`] = read;

        // A PIXEL READ OF THE LABEL, because opacity composites and getComputedStyle cannot
        // see the composite. Only needed where opacity is actually in play, but taken on
        // every scene so the inverted numbers have an un-dimmed control to be compared to.
        const labelBox = await page.evaluate(() => {
          const el = document.querySelector('.stats__label, .logos__label');
          if (!el) return null;
          const b = el.getBoundingClientRect();
          return { x: b.x, y: b.y, w: b.width, h: b.height };
        });
        if (labelBox && labelBox.w > 2 && labelBox.h > 2) {
          const shot = `${SHOTS}/${scene.key}-w${w}-label.png`;
          await page.screenshot({
            path: shot,
            clip: { x: Math.max(0, labelBox.x), y: Math.max(0, labelBox.y), width: Math.min(labelBox.w, 400), height: Math.min(labelBox.h, 60) },
          });
          (perScene[`w${w}`] as Record<string, unknown>).__labelShot = shot;
        }
        await page.screenshot({ path: `${SHOTS}/${scene.key}-w${w}-band.png`, fullPage: false });
      }
    } finally {
      try { sh(`npx wp-env run cli wp post delete ${id} --force`); } catch { /* ignore */ }
    }
    out[scene.key] = perScene;
  }

  fs.writeFileSync(OUT, JSON.stringify(out, null, 1));
  console.log(`WROTE ${OUT}`);
});
