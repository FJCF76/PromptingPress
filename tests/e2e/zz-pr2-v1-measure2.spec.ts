import { test } from '@playwright/test';
import { execSync } from 'child_process';
import fs from 'fs';

/**
 * #1066 PR2 SUPPLEMENTARY MEASUREMENT — NOT A PIN. Deleted before the PR.
 *
 * THE FIRST PASS HAD A HOLE AND THIS FILLS IT. `.logos--inverted .logos__label` is the
 * SECOND half of the A-36 pin (the stats half measured cleanly at rgb(192,195,201)), but
 * the inverted logos scene was built from label-less fixtures, so the rule never matched
 * and the opacity went unmeasured. A roster pin measured on one of its two members is the
 * #1038 shape — half a claim, repriced.
 *
 * Also measures the labelled-tile image cap on an inverted band, because the 3rem/2.5rem
 * switch and the theme are independent and the port has to keep them independent.
 */

const OUT = '/home/wfroot-n5i9y/.claude/pp-orchestrator/evidence-1066-pr2/pr2-v1-measure2.json';
const SHOTS = '/home/wfroot-n5i9y/.claude/pp-orchestrator/evidence-1066-pr2/shots-v1';

function sh(cmd: string): string {
  return execSync(cmd, { cwd: process.cwd(), encoding: 'utf-8' }).trim();
}

test('#1066 PR2 — the logos half of A-36, on a labelled inverted strip', async ({ page }) => {
  test.setTimeout(10 * 60 * 1000);
  fs.mkdirSync(SHOTS, { recursive: true });

  const id = parseInt(
    sh(`npx wp-env run cli wp post create --post_type=page --post_status=publish --post_author=1 --post_title="pr2-logos-inv-labelled" --porcelain`),
    10,
  );
  sh(`npx wp-env run cli wp post meta update ${id} _wp_page_template composition.php`);
  const composition = [
    {
      component: 'logos',
      props: {
        id: 'pp-l5',
        title: 'Trusted by',
        theme: 'inverted',
        items: [
          { image_url: '/wp-content/uploads/a.png', image_alt: 'A', label: 'Labelled tile' },
          { image_url: '/wp-content/uploads/b.png', image_alt: 'B' },
        ],
      },
    },
  ];
  sh(`npx wp-env run cli wp post meta update ${id} _pp_composition '${JSON.stringify(composition).replace(/'/g, "'\\''")}'`);

  const out: Record<string, unknown> = {};
  try {
    for (const w of [375, 768, 1280]) {
      await page.setViewportSize({ width: w, height: 1000 });
      await page.goto(`/?page_id=${id}`, { waitUntil: 'load', timeout: 30000 });
      const read = await page.evaluate(() => {
        const grab = (sel: string) => {
          const el = document.querySelector(sel);
          if (!el) return null;
          const cs = getComputedStyle(el);
          const b = el.getBoundingClientRect();
          return {
            color: cs.color, opacity: cs.opacity, fontSize: cs.fontSize,
            textAlign: cs.textAlign, maxHeight: cs.maxHeight,
            box: { x: Math.round(b.x), y: Math.round(b.y), w: Math.round(b.width), h: Math.round(b.height) },
          };
        };
        return {
          band: grab('[data-pp-component="logos"]'),
          label: grab('.logos__label'),
          labeledImage: grab('.logos__item--labeled .logos__image'),
          plainImage: grab('.logos__item:not(.logos__item--labeled) .logos__image'),
        };
      });
      out[`w${w}`] = read;

      const lb = await page.evaluate(() => {
        const el = document.querySelector('.logos__label');
        if (!el) return null;
        const b = el.getBoundingClientRect();
        return { x: b.x, y: b.y, w: b.width, h: b.height };
      });
      if (lb && lb.w > 2 && lb.h > 2) {
        await page.screenshot({
          path: `${SHOTS}/logos-inverted-labelled-w${w}-label.png`,
          clip: { x: Math.max(0, lb.x), y: Math.max(0, lb.y), width: Math.min(lb.w, 400), height: Math.min(lb.h, 60) },
        });
      }
    }
  } finally {
    try { sh(`npx wp-env run cli wp post delete ${id} --force`); } catch { /* ignore */ }
  }

  fs.writeFileSync(OUT, JSON.stringify(out, null, 1));
  console.log(JSON.stringify(out, null, 1));
});
