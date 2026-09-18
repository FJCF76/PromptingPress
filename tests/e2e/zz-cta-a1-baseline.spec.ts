import { test } from '@playwright/test';
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

/**
 * #1026 A1 PROBE — NOT A PIN. Deleted before the PR.
 *
 * `docs/explanation-cascade-layers.md` §1c: "the way you find out is by reading the
 * computed value on a real page — the border's absence is invisible to a schema, to the
 * emitter, and to a specificity argument made on paper." Run once BEFORE the narrowing
 * and once AFTER, same scene, same tiers.
 *
 * Reads the band roots of THREE components, not one: cta (rebuilt, the subject), grid
 * (still v1, still emitting inline slot custom properties — must keep its immunity) and
 * section (rebuilt at #1023, whose `_band` border default is zero-width so the collision
 * was invisible there).
 */

const OUT = process.env.PP_A1_OUT || '/tmp/cta-a1.json';
const TIERS = [
  { name: 'p', width: 375 },
  { name: 't', width: 768 },
  { name: 'd', width: 1280 },
];

const PROPS = [
  'border-top-width', 'border-top-style', 'border-top-color',
  'border-bottom-width', 'border-bottom-style', 'border-bottom-color',
  'border-left-width', 'border-left-style',
];

function createPage(title: string): number {
  const cmd = `npx wp-env run cli wp post create --post_type=page --post_status=publish --post_author=1 --post_title="${title}" --porcelain`;
  const id = parseInt(execSync(cmd, { cwd: process.cwd(), encoding: 'utf-8' }).trim(), 10);
  execSync(`npx wp-env run cli wp post meta update ${id} _wp_page_template composition.php`, { cwd: process.cwd() });
  return id;
}

/** The REAL write path — raw meta mints no band id, so a v2 band written that way scopes to nothing. */
async function updateComposition(page: any, postId: number, composition: unknown[]) {
  return page.evaluate(
    async (args: { pid: number; composition: unknown[] }) => {
      const config = (window as any).ppAiChat;
      const bd = new FormData();
      bd.append('action', 'pp_ai_page_baseline');
      bd.append('nonce', config.executeNonce);
      bd.append('post_id', String(args.pid));
      const br = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: bd });
      const baseline = await br.json();
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
      const resp = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
      return resp.json();
    },
    { pid: postId, composition },
  );
}

test('#1026 A1 — read the band border longhands in Chromium', async ({ page }) => {
  test.setTimeout(10 * 60 * 1000);
  const id = createPage('pp1026-a1');
  const out: Record<string, any> = {};
  try {
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    const res = await updateComposition(page, id, [
      {
        component: 'cta',
        props: {
          title: 'Protect the margin',
          body: 'Supporting line.',
          button_text: 'Go',
          button_url: '/go',
        },
      },
      {
        component: 'section',
        props: { title: 'A section', body: '<p>Prose.</p>' },
      },
      {
        component: 'grid',
        props: {
          title: 'A grid',
          items: [{ title: 'Card', text: 'Body' }],
        },
        // The style map is a TOP-LEVEL item key, not a prop — a v1 band's slots live
        // beside `props`, never inside it. Getting this wrong is a silent no-op in a
        // raw-meta fixture and an explicit refusal through the real write path, which is
        // the reason this probe authors through the action surface.
        style: { '--grid-item-border-width': '1px', '--grid-item-border-color': '#ff0000' },
      },
    ]);
    out._write = res;

    for (const tier of TIERS) {
      await page.setViewportSize({ width: tier.width, height: 1000 });
      await page.goto(`/?page_id=${id}`, { waitUntil: 'networkidle' });
      out[tier.name] = await page.evaluate((props) => {
        const read = (sel: string) => {
          const el = document.querySelector(sel);
          if (!el) return { missing: true };
          const cs = getComputedStyle(el);
          const o: Record<string, string> = {};
          for (const p of props) o[p] = cs.getPropertyValue(p);
          return { css: o, styleAttr: el.getAttribute('style'), band: el.getAttribute('data-pp-band') };
        };
        return {
          cta: read('.cta'),
          section: read('.section'),
          grid: read('.grid'),
          gridItem: read('.grid__item'),
        };
      }, PROPS);
    }
  } finally {
    execSync(`npx wp-env run cli wp post delete ${id} --force`, { cwd: process.cwd() });
  }
  fs.mkdirSync(path.dirname(OUT), { recursive: true });
  fs.writeFileSync(OUT, JSON.stringify(out, null, 1));
  console.log(`WROTE ${OUT}`);
  console.log(JSON.stringify(out, null, 1));
});
